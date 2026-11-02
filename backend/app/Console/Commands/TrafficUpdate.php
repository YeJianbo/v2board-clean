<?php

namespace App\Console\Commands;

use App\Services\StatisticalService;
use App\Services\ProcessingBatchService;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficUpdate extends Command
{
    private const USER_BATCH_SIZE = 500;
    private bool $accountingFailed = false;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量更新任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->accountingFailed = false;
        $lock = Cache::lock('traffic:update:command', 600);
        if (!$lock->get()) {
            return 0;
        }

        try {
            $this->handleLocked();
        } finally {
            $lock->release();
        }

        return $this->accountingFailed ? self::FAILURE : self::SUCCESS;
    }

    private function handleLocked(): void
    {
        if (Redis::exists('traffic_reset_lock')) {
            return;
        }

        $this->drainTrafficInbox();

        $batchService = app(ProcessingBatchService::class);
        $batchService->recover();
        $this->flushStatCaches();

        $batchId = bin2hex(random_bytes(16));
        [$uploadBatch, $uploads] = $batchService->move('v2board_upload_traffic', 'hash', $batchId);
        [$downloadBatch, $downloads] = $batchService->move('v2board_download_traffic', 'hash', $batchId);
        if (empty($uploads) && empty($downloads)) {
            $batchService->complete($uploadBatch);
            $batchService->complete($downloadBatch);
            return;
        }

        try {
            $userIds = array_values(array_unique(array_merge(array_keys($uploads), array_keys($downloads))));
            sort($userIds, SORT_NUMERIC);
            $time = time();
            // SQL is bounded, but the receipt and every increment still commit together.
            DB::transaction(function () use ($uploads, $downloads, $time, $batchService, $batchId) {
                $batchService->record($batchId, 'user_traffic');
                app(\App\Services\UserTrafficAccountingService::class)->apply($uploads, $downloads, $time);
            }, 3);
        } catch (\Throwable $e) {
            // A commit response can be lost; recover() must check the original receipt first.
            $this->accountingFailed = true;
            \Log::error('流量更新失败: ' . $e->getMessage());
            return;
        }

        try {
            $batchService->complete($uploadBatch);
            $batchService->complete($downloadBatch);
        } catch (\Throwable $e) {
            // Database changes are committed; restoring here would count the same traffic twice.
            $this->accountingFailed = true;
            \Log::warning('流量处理缓存清理失败: ' . $e->getMessage());
        }

        $this->sendTrafficReminders($userIds);
    }

    private function drainTrafficInbox(): void
    {
        $inbox = app(\App\Services\TrafficInboxService::class);
        $aggregator = app(\App\Services\TrafficReportAggregator::class);
        $accounting = app(\App\Services\UserTrafficAccountingService::class);
        $statistics = app(StatisticalService::class);
        $started = microtime(true);
        do {
            $userIds = [];
            $count = $inbox->consumeBatch(function (array $reports) use ($aggregator, $accounting, $statistics, &$userIds): void {
                $cutoffs = app(\App\Services\TrafficResetCheckpointService::class)->lockForReports($reports);
                $batch = $aggregator->aggregate($reports, $cutoffs);
                $userIds = $accounting->apply($batch['uploads'], $batch['downloads'], time());
                $statistics->applyTrafficStatistics($batch['statistics']);
            });
            if (!$count) break;
            $this->sendTrafficReminders($userIds);
            if (Redis::exists('traffic_reset_lock')) break;
        } while (microtime(true) - $started < 30);
    }

    private function sendTrafficReminders(array $userIds): void
    {
        $notificationService = app(TelegramNotificationService::class);
        foreach (array_chunk($userIds, self::USER_BATCH_SIZE) as $chunk) {
            try {
                $users = User::whereIn('id', $chunk)->orderBy('id')->get([
                    'id', 'u', 'd', 'transfer_enable', 'expired_at', 'remind_traffic', 'telegram_id',
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Telegram流量提醒读取失败', ['exception_type' => get_class($e), 'exception' => $e]);
                continue;
            }
            foreach ($users as $user) {
                try {
                    $notificationService->remindUserTraffic($user);
                } catch (\Throwable $e) {
                    \Log::warning('Telegram流量提醒处理失败', [
                        'user_id' => $user->id,
                        'exception_type' => get_class($e),
                        'exception' => $e,
                    ]);
                }
            }
        }
    }

    private function flushStatCaches(): void
    {
        $service = app(StatisticalService::class);
        $now = time();
        $days = array_unique([
            strtotime(date('Y-m-d', $now)),
            strtotime(date('Y-m-d', $now - 86400)),
        ]);
        $hours = [];
        for ($i = 0; $i <= 2; $i++) {
            $hours[] = strtotime(date('Y-m-d H:00:00', $now - ($i * 3600)));
        }
        $minutes = [];
        for ($i = 0; $i <= 5; $i++) {
            $minutes[] = strtotime(date('Y-m-d H:i:00', $now - ($i * 60)));
        }

        try {
            foreach ($days as $recordAt) {
                if ($recordAt <= 0) {
                    continue;
                }
                $service->flushStatServer($recordAt, 'd');
                $service->flushStatUser($recordAt, 'd');
                $service->flushStatUserServer('v2_stat_user_server', $recordAt, 'd');
            }

            foreach (array_unique($hours) as $recordAt) {
                if ($recordAt > 0) {
                    $service->flushStatUserServer('v2_stat_user_server_hour', $recordAt, 'h');
                }
            }

            foreach (array_unique($minutes) as $recordAt) {
                if ($recordAt > 0) {
                    $service->flushStatUserServer('v2_stat_user_server_minute', $recordAt, 'm');
                }
            }
        } catch (\Throwable $e) {
            $this->accountingFailed = true;
            \Log::error('统计缓存落库失败: ' . $e->getMessage());
        }
    }
}
