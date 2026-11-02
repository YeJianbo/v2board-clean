<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ResetTraffic extends Command
{
    private const USER_BATCH_SIZE = 500;
    protected $signature = 'reset:traffic';
    protected $description = '流量清空';
    private int $now;
    private int $inboxWatermark;

    public function handle(): int
    {
        try {
            // Serialize reset with the counter updater, not just other reset commands.
            return Cache::lock('traffic:update:command', 600)->block(60, fn () => $this->handleLocked());
        } catch (LockTimeoutException $exception) {
            $this->error('流量更新任务仍在运行，本次重置未执行');
            return 1;
        }
    }

    private function handleLocked(): int
    {
        $this->now = Carbon::now()->timestamp;
        Redis::setex('traffic_reset_lock', 600, 1);
        try {
            $this->inboxWatermark = app(\App\Services\TrafficResetCheckpointService::class)->watermark();
            $methods = Plan::query()->select('reset_traffic_method')->distinct()->pluck('reset_traffic_method');
            foreach ($methods as $configuredMethod) {
                $method = (int) ($configuredMethod ?? config('v2board.reset_traffic_method', 0));
                $plans = Plan::query()->select('id');
                $configuredMethod === null
                    ? $plans->whereNull('reset_traffic_method')
                    : $plans->where('reset_traffic_method', $configuredMethod);
                $users = User::query()->whereNotNull('expired_at')->where('expired_at', '>', $this->now)
                    ->whereIn('plan_id', $plans);
                switch ($method) {
                    case Plan::RESET_TRAFFIC_FIRST_DAY_MONTH:
                        if (date('d', $this->now) === '01') $this->resetAll($users);
                        break;
                    case Plan::RESET_TRAFFIC_MONTHLY:
                        $this->resetByDate($users, function ($user): bool {
                            $today = date('d', $this->now);
                            $lastDay = date('t', $this->now);
                            $expireDay = date('d', $user->expired_at);
                            return ($expireDay === $today || ($today === $lastDay && $expireDay >= $lastDay))
                                && $this->now < $user->expired_at - 2160000;
                        });
                        break;
                    case Plan::RESET_TRAFFIC_FIRST_DAY_YEAR:
                        if (date('md', $this->now) === '0101') $this->resetAll($users);
                        break;
                    case Plan::RESET_TRAFFIC_YEARLY:
                        $this->resetByDate($users, fn ($user) => date('m-d', $user->expired_at) === date('m-d', $this->now));
                        break;
                }
            }
        } finally {
            Redis::del('traffic_reset_lock');
        }
        return 0;
    }

    private function resetAll($users): void
    {
        $this->retryTransaction(function () use ($users) {
            (clone $users)->select('id')->lockForUpdate()->chunkById(self::USER_BATCH_SIZE, function ($chunk) {
                $ids = $chunk->pluck('id')->all();
                User::whereIn('id', $ids)->update(['u' => 0, 'd' => 0]);
                app(\App\Services\TrafficResetCheckpointService::class)->record($ids, $this->inboxWatermark);
            });
        });
    }

    private function resetByDate($users, callable $due): void
    {
        // A later chunk failure rolls back the whole reset for this plan policy.
        $this->retryTransaction(function () use ($users, $due) {
            (clone $users)->select(['id', 'expired_at'])->lockForUpdate()->chunkById(self::USER_BATCH_SIZE, function ($chunk) use ($due) {
                $ids = $chunk->filter($due)->pluck('id')->all();
                if ($ids) {
                    User::whereIn('id', $ids)->update(['u' => 0, 'd' => 0]);
                    app(\App\Services\TrafficResetCheckpointService::class)->record($ids, $this->inboxWatermark);
                }
            });
        });
    }

    private function retryTransaction(callable $callback): void
    {
        try {
            DB::transaction($callback, 3);
        } catch (\Throwable $exception) {
            Log::error('用户流量重置失败', ['exception_type' => get_class($exception), 'code' => $exception->getCode(), 'exception' => $exception]);
            try {
                app(TelegramService::class)->sendMessageWithAdmin('用户流量重置失败，请检查后台日志');
            } catch (\Throwable $notificationError) {
                Log::warning('流量重置告警发送失败', ['exception_type' => get_class($notificationError), 'exception' => $notificationError]);
            }
            throw new \RuntimeException('用户流量重置失败', 0, $exception);
        }
    }
}
