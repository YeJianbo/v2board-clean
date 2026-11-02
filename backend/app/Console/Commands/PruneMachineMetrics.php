<?php

namespace App\Console\Commands;

use App\Services\MachineMetricService;
use Illuminate\Console\Command;

class PruneMachineMetrics extends Command
{
    protected $signature = 'machine:prune-metrics
        {--days= : Legacy alias for minute retention days}
        {--minute-days=7 : Minute metric retention days}
        {--hour-days=30 : Hourly metric retention days}
        {--network-days=31 : Network quality retention days}
        {--batch=5000 : Rows per delete batch}';
    protected $description = 'Prune expired machine resource and network quality metrics';

    public function handle(MachineMetricService $metrics): int
    {
        foreach (['days', 'minute-days', 'hour-days', 'network-days', 'batch'] as $name) {
            $value = $this->option($name);
            if ($value === null) continue;
            $min = $name === 'batch' ? 100 : 1;
            $max = $name === 'batch' ? 10000 : 90;
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
                $this->error("--{$name} must be an integer between {$min} and {$max}; no rows were deleted.");
                return self::INVALID;
            }
        }
        $legacyDays = $this->option('days');
        $minuteDays = is_numeric($legacyDays)
            ? (int) $legacyDays
            : (int) $this->option('minute-days');
        $batchSize = (int) $this->option('batch');
        $minuteDeleted = $metrics->prune($minuteDays, $batchSize);
        $hourDeleted = $metrics->pruneHourly((int) $this->option('hour-days'), $batchSize);
        $networkDeleted = $metrics->pruneNetworkQuality((int) $this->option('network-days'), $batchSize);
        $this->info(sprintf(
            'Deleted %d minute rows and %d hourly rows.',
            $minuteDeleted,
            $hourDeleted
        ));
        $this->info(sprintf('Deleted %d network quality rows.', $networkDeleted));

        return 0;
    }
}
