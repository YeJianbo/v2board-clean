<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverTrafficTotal extends Command
{
    protected $signature = 'traffic:recover-total {--apply : Save verified missing traffic in the permanent archive}';
    protected $description = 'Recover deleted server totals from matching retained user-node daily detail';

    public function handle(): int
    {
        return DB::transaction(function () {
            if (DB::table('v2_traffic_archive')->where('source', 'server_recovery')->exists()) {
                $this->info('Recovery already recorded; no changes.');
                return 0;
            }
            if (DB::table('v2_traffic_archive')->where('source', 'v2_stat_server')->exists()) {
                $this->error('Server archive already exists; manual reconciliation required.');
                return 1;
            }
            $cutoff = DB::table('v2_stat_server')->min('record_at');
            if (!$cutoff) { $this->error('No server baseline available.'); return 1; }
            $server = DB::table('v2_stat_server')->where('record_at', '<', strtotime('today'))
                ->selectRaw('record_at, SUM(u) as u, SUM(d) as d')->groupBy('record_at')->get()->keyBy('record_at');
            $detail = DB::table('v2_stat_user_server')->where('record_at', '>=', $cutoff)->where('record_at', '<', strtotime('today'))
                ->selectRaw('record_at, SUM(u) as u, SUM(d) as d')->groupBy('record_at')->get()->keyBy('record_at');
            if ($server->isEmpty() || $server->count() !== $detail->count()) { $this->error('Insufficient matching baseline.'); return 1; }
            foreach ($server as $day => $row) {
                $other = $detail->get($day);
                if (!$other || (int) $row->u !== (int) $other->u || (int) $row->d !== (int) $other->d) {
                    $this->error('Daily totals differ; recovery refused.'); return 1;
                }
            }
            $old = DB::table('v2_stat_user_server')->where('record_at', '<', $cutoff)
                ->selectRaw('COALESCE(SUM(u),0) as upload, COALESCE(SUM(d),0) as download, MIN(record_at) as since')->first();
            $this->info(json_encode(['matched_days' => $server->count(), 'before' => $cutoff, 'recoverable' => $old]));
            if ($this->option('apply') && $old->since) {
                DB::table('v2_traffic_archive')->insertOrIgnore(['source' => 'server_recovery', 'subject' => 'legacy_missing_days',
                    'upload' => (int) $old->upload, 'download' => (int) $old->download]);
                $this->info('Recovered total saved; existing details unchanged.');
            }
            return 0;
        }, 3);
    }
}
