<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\Plugin\HookManager;

/**
 * Service for handling traffic reset.
 */
class TrafficResetService
{
  /**
   * Check if a user's traffic should be reset and perform the reset.
   */
  public function checkAndReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_AUTO): bool
  {
    if (!$this->isResetDue($user)) {
      return false;
    }

    return $this->performReset($user, $triggerSource);
  }

  /**
   * Perform the traffic reset for a user.
   */
  public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL, array $metadata = []): bool
  {
    try {
      return DB::transaction(function () use ($user, $triggerSource, $metadata) {
        $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($triggerSource === TrafficResetLog::SOURCE_MANUAL && !$this->canReset($user)) return false;
        if (in_array($triggerSource, [TrafficResetLog::SOURCE_AUTO, TrafficResetLog::SOURCE_CRON], true)
          && !$this->isResetDue($user)) return false;
        $oldUpload = $user->u ?? 0;
        $oldDownload = $user->d ?? 0;
        $oldTotal = $oldUpload + $oldDownload;

        $updates = ['u' => 0, 'd' => 0];
        // Older installations only have u/d; never write uninstalled optional columns.
        $attributes = $user->getAttributes();
        if (array_key_exists('last_reset_at', $attributes)) $updates['last_reset_at'] = time();
        if (array_key_exists('reset_count', $attributes)) $updates['reset_count'] = (int) $user->reset_count + 1;
        if (array_key_exists('next_reset_at', $attributes)) {
          $nextResetTime = $this->calculateNextResetTime($user);
          $updates['next_reset_at'] = $nextResetTime ? $nextResetTime->timestamp : null;
        }
        if (!$user->update($updates)) throw new \RuntimeException('Traffic reset save rejected');
        app(TrafficResetCheckpointService::class)->record([(int) $user->id]);

        $this->recordResetLog($user, [
          'reset_type' => $this->getResetTypeFromPlan($user->plan),
          'trigger_source' => $triggerSource,
          'old_upload' => $oldUpload,
          'old_download' => $oldDownload,
          'old_total' => $oldTotal,
          'new_upload' => 0,
          'new_download' => 0,
          'new_total' => 0,
          'metadata' => $metadata,
        ]);

        $this->clearUserCache($user);
        HookManager::call('traffic.reset.after', $user);
        return true;
      });
    } catch (\Exception $e) {
      Log::error(__('traffic_reset.reset_failed'), [
        'user_id' => $user->id,
        'email' => $user->email,
        'error' => $e->getMessage(),
        'trigger_source' => $triggerSource,
      ]);

      return false;
    }
  }

  /**
   * Calculate the next traffic reset time for a user.
   */
  public function calculateNextResetTime(User $user): ?Carbon
  {
    if (
      !$user->plan
      || $user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_NEVER
      || ($user->plan->reset_traffic_method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM
        && (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY) === Plan::RESET_TRAFFIC_NEVER)
      || $user->expired_at === NULL
    ) {
      return null;
    }

    $resetMethod = $user->plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    $now = Carbon::now(config('app.timezone'));

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => $this->getNextMonthFirstDay($now),
      Plan::RESET_TRAFFIC_MONTHLY => $this->getNextMonthlyReset($user, $now),
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => $this->getNextYearFirstDay($now),
      Plan::RESET_TRAFFIC_YEARLY => $this->getNextYearlyReset($user, $now),
      default => null,
    };
  }

  /**
   * Get the first day of the next month.
   */
  private function getNextMonthFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->startOfMonth()->addMonth();
  }

  /**
   * Get the next monthly reset time based on the user's expiration date.
   *
   * Clamp to the month's last day without changing the original anniversary.
   */
  private function getNextMonthlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];
    
    $month = $from->copy()->startOfMonth();
    $currentMonthTarget = $month->copy()->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
    if ($currentMonthTarget->timestamp > $from->timestamp) {
      return $currentMonthTarget;
    }
    
    $month->addMonth();
    return $month->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
  }

  /**
   * Get the first day of the next year.
   */
  private function getNextYearFirstDay(Carbon $from): Carbon
  {
    return $from->copy()->startOfYear()->addYear();
  }

  /**
   * Get the next yearly reset time based on the user's expiration date.
   *
   * Clamp leap-day anniversaries before comparing this year's target with now.
   */
  private function getNextYearlyReset(User $user, Carbon $from): Carbon
  {
    $expiredAt = Carbon::createFromTimestamp($user->expired_at, config('app.timezone'));
    $resetMonth = $expiredAt->month;
    $resetDay = $expiredAt->day;
    $resetTime = [$expiredAt->hour, $expiredAt->minute, $expiredAt->second];

    $month = $from->copy()->startOfYear()->month($resetMonth);
    $currentYearTarget = $month->copy()->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
    if ($currentYearTarget->timestamp > $from->timestamp) {
      return $currentYearTarget;
    }
    
    $month->addYear();
    return $month->day(min($resetDay, $month->daysInMonth))->setTime(...$resetTime);
  }


  /**
   * Record the traffic reset log.
   */
  private function recordResetLog(User $user, array $data): void
  {
    TrafficResetLog::create([
      'user_id' => $user->id,
      'reset_type' => $data['reset_type'],
      'reset_time' => now(),
      'old_upload' => $data['old_upload'],
      'old_download' => $data['old_download'],
      'old_total' => $data['old_total'],
      'new_upload' => $data['new_upload'],
      'new_download' => $data['new_download'],
      'new_total' => $data['new_total'],
      'trigger_source' => $data['trigger_source'],
      'metadata' => $data['metadata'] ?? null,
    ]);
  }

  /**
   * Get the reset type from the user's plan.
   */
  private function getResetTypeFromPlan(?Plan $plan): string
  {
    if (!$plan) {
      return TrafficResetLog::TYPE_MANUAL;
    }

    $resetMethod = $plan->reset_traffic_method;

    if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
      $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    }

    return match ($resetMethod) {
      Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => TrafficResetLog::TYPE_FIRST_DAY_MONTH,
      Plan::RESET_TRAFFIC_MONTHLY => TrafficResetLog::TYPE_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => TrafficResetLog::TYPE_FIRST_DAY_YEAR,
      Plan::RESET_TRAFFIC_YEARLY => TrafficResetLog::TYPE_YEARLY,
      Plan::RESET_TRAFFIC_NEVER => TrafficResetLog::TYPE_MANUAL,
      default => TrafficResetLog::TYPE_MANUAL,
    };
  }

  /**
   * Clear user-related cache.
   */
  private function clearUserCache(User $user): void
  {
    $cacheKeys = [
      "user_traffic_{$user->id}",
      "user_reset_status_{$user->id}",
      "user_subscription_{$user->token}",
    ];

    foreach ($cacheKeys as $key) {
      Cache::forget($key);
    }
  }

  /**
   * Batch check and reset users. Processes all eligible users in batches.
   */
  public function batchCheckReset(int $batchSize = 100, ?callable $progressCallback = null): array
  {
    $startTime = microtime(true);
    $totalResetCount = 0;
    $totalProcessedCount = 0;
    $batchNumber = 1;
    $errors = [];
    $lastProcessedId = 0;

    try {
      do {
        $users = User::where('next_reset_at', '<=', time())
          ->whereNotNull('next_reset_at')
          ->where('id', '>', $lastProcessedId)
          ->where(function ($query) {
            $query->where('expired_at', '>', time())
              ->orWhereNull('expired_at');
          })
          ->where('banned', 0)
          ->whereNotNull('plan_id')
          ->orderBy('id')
          ->limit($batchSize)
          ->get();

        if ($users->isEmpty()) {
          break;
        }

        $batchResetCount = 0;

        if ($progressCallback) {
          $progressCallback([
            'batch_number' => $batchNumber,
            'batch_size' => $users->count(),
            'total_processed' => $totalProcessedCount,
          ]);
        }

        foreach ($users as $user) {
          try {
            if ($this->checkAndReset($user, TrafficResetLog::SOURCE_CRON)) {
              $batchResetCount++;
              $totalResetCount++;
            }
            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          } catch (\Exception $e) {
            $error = [
              'user_id' => $user->id,
              'email' => $user->email,
              'error' => $e->getMessage(),
              'batch' => $batchNumber,
              'timestamp' => now()->toDateTimeString(),
            ];
            $batchErrors[] = $error;
            $errors[] = $error;

            Log::error('User traffic reset failed', $error);

            $totalProcessedCount++;
            $lastProcessedId = $user->id;
          }
        }

        $batchNumber++;

        if ($batchNumber % 10 === 0) {
          gc_collect_cycles();
        }

        if ($batchNumber % 5 === 0) {
          usleep(100000);
        }

      } while (true);

    } catch (\Exception $e) {
      Log::error('Batch traffic reset task failed with an exception', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'total_processed' => $totalProcessedCount,
        'total_reset' => $totalResetCount,
        'last_processed_id' => $lastProcessedId,
      ]);

      $errors[] = [
        'type' => 'system_error',
        'error' => $e->getMessage(),
        'batch' => $batchNumber,
        'last_processed_id' => $lastProcessedId,
        'timestamp' => now()->toDateTimeString(),
      ];
    }

    $totalDuration = round(microtime(true) - $startTime, 2);

    $result = [
      'total_processed' => $totalProcessedCount,
      'total_reset' => $totalResetCount,
      'total_batches' => $batchNumber - 1,
      'error_count' => count($errors),
      'errors' => $errors,
      'duration' => $totalDuration,
      'batch_size' => $batchSize,
      'last_processed_id' => $lastProcessedId,
      'completed_at' => now()->toDateTimeString(),
    ];

    return $result;
  }

  /**
   * Set the initial reset time for a new user.
   */
  public function setInitialResetTime(User $user): void
  {
    if ($user->next_reset_at !== null) {
      return;
    }

    $nextResetTime = $this->calculateNextResetTime($user);

    if ($nextResetTime) {
      $user->update(['next_reset_at' => $nextResetTime->timestamp]);
    }
  }

  /**
   * Get the user's traffic reset history.
   */
  public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
  {
    return TrafficResetLog::where('user_id', $user->id)
      ->orderBy('reset_time', 'desc')
      ->orderBy('id', 'desc')
      ->limit($limit)
      ->get();
  }

  /**
   * Check if the user is eligible for traffic reset.
   */
  public function canReset(User $user): bool
  {
    return (int) $user->banned === 0
      && ($user->expired_at === null || (int) $user->expired_at > time())
      && $user->plan !== null;
  }

  private function isResetDue(User $user): bool
  {
    if ($user->next_reset_at === null || (int) $user->next_reset_at > time() || !$this->canReset($user)) return false;
    $method = $user->plan->reset_traffic_method ?? admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
    return in_array((int) $method, [Plan::RESET_TRAFFIC_FIRST_DAY_MONTH, Plan::RESET_TRAFFIC_MONTHLY,
      Plan::RESET_TRAFFIC_FIRST_DAY_YEAR, Plan::RESET_TRAFFIC_YEARLY], true);
  }

  /**
   * Manually reset a user's traffic (Admin function).
   */
  public function manualReset(User $user, array $metadata = []): bool
  {
    if (!$this->canReset($user)) {
      return false;
    }

    return $this->performReset($user, TrafficResetLog::SOURCE_MANUAL, $metadata);
  }
}
