<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneTrafficStatistics extends Command
{
    protected $signature = 'traffic:prune-statistics
        {--minute-days= : Override configured minute retention days}
        {--hour-days= : Override configured hour retention days}
        {--daily-days= : Override configured daily retention days}
        {--batch=5000 : Rows deleted per transaction}';

    protected $description = 'Prune expired traffic statistics in bounded batches';

    public function handle(): int
    {
        foreach (['minute-days', 'hour-days', 'daily-days', 'batch'] as $name) {
            $value = $this->option($name);
            if ($value === null) continue;
            $min = $name === 'batch' ? 100 : 1;
            $max = $name === 'batch' ? 20000 : 3650;
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
                $this->error("--{$name} must be an integer between {$min} and {$max}; no rows were deleted.");
                return self::INVALID;
            }
        }
        $batch = max(100, min((int)$this->option('batch'), 20000));
        $minute = (int) ($this->option('minute-days') ?? \App\Services\TrafficRetention::days('minute'));
        $hour = (int) ($this->option('hour-days') ?? \App\Services\TrafficRetention::days('hour'));
        $daily = (int) ($this->option('daily-days') ?? \App\Services\TrafficRetention::days('daily'));
        $policies = [
            'v2_stat_user_server_minute' => max(1, $minute),
            'v2_stat_user_server_hour' => max(1, $hour),
            'v2_stat_user_server' => max(1, $daily),
            'v2_stat_user' => max(1, $daily),
            'v2_stat_server' => max(1, $daily),
        ];

        foreach ($policies as $table => $days) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $cutoff = strtotime('today') - $days * 86400;
            $deleted = in_array($table, ['v2_stat_user_server', 'v2_stat_user', 'v2_stat_server'], true)
                ? app(\App\Services\TrafficRetention::class)->prune($table, 'record_at', $cutoff, ['id'], min($batch, 500))
                : $this->deleteInBatches($table, $cutoff, $batch);
            $this->line("{$table}: {$deleted} rows deleted");
        }

        if (Schema::hasTable('v2_processing_batch')) {
            $deleted = app(\App\Services\ProcessingBatchService::class)->pruneReceipts($batch);
            $this->line("v2_processing_batch: {$deleted} rows deleted");
        }

        return 0;
    }

    private function deleteInBatches(string $table, int $cutoff, int $batch): int
    {
        $deleted = 0;
        do {
            $ids = DB::table($table)
                ->where('record_at', '<', $cutoff)
                ->orderBy('record_at')
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id')
                ->all();
            if (!$ids) {
                break;
            }
            $count = DB::transaction(static function () use ($table, $ids, $cutoff) {
                return DB::table($table)->whereIn('id', $ids)->where('record_at', '<', $cutoff)->delete();
            }, 3);
            $deleted += (int)$count;
        } while (count($ids) === $batch);

        return $deleted;
    }
}
