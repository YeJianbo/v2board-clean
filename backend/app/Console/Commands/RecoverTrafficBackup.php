<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverTrafficBackup extends Command
{
    protected $signature = 'traffic:recover-backup {file : Audited statistics JSON, not a SQL dump} {--apply}';
    protected $description = 'Import non-overlapping historical server totals without restoring account or node data';

    public function handle(): int
    {
        $data = json_decode(file_get_contents($this->argument('file')), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($data['rows'], $data['conflicts']) || $data['conflicts']) {
            $this->error('Unresolved backup conflicts.'); return 1;
        }
        return DB::transaction(function () use ($data) {
            // Stay entirely before both surviving sources, including the detail recovery window.
            $starts = [DB::table('v2_stat_server')->min('record_at'), DB::table('v2_stat_user_server')->min('record_at')];
            if (!$starts[0] || !$starts[1] || DB::table('v2_traffic_archive')->where('source', 'v2_stat_server')->exists()) {
                $this->error('Baseline missing or already pruned; reconcile before importing.'); return 1;
            }
            $cutoff = min($starts);
            $records = []; $upload = 0; $download = 0;
            foreach ($data['rows'] as $row) {
                foreach (['record_at', 'server_id', 'u', 'd'] as $field) {
                    if (!isset($row[$field]) || !is_int($row[$field]) || $row[$field] < 0) {
                        $this->error('Invalid numeric record.'); return 1;
                    }
                }
                if (!is_string($row['server_type'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $row['server_type'])) {
                    $this->error('Invalid server type.'); return 1;
                }
                if ($row['record_at'] >= $cutoff) { $this->error('Backup overlaps surviving traffic.'); return 1; }
                $key = $row['server_type'] . ':' . $row['server_id'] . ':' . $row['record_at'];
                if (isset($records[$key])) { $this->error('Duplicate backup key.'); return 1; }
                $records[$key] = ['source'=>'server_backup', 'subject'=>$key, 'upload'=>$row['u'], 'download'=>$row['d']];
            }
            $existing = DB::table('v2_traffic_archive')->where('source', 'server_backup')->lockForUpdate()->get()->keyBy('subject');
            foreach ($records as $key=>$row) {
                if (isset($existing[$key])) {
                    if ((int)$existing[$key]->upload !== $row['upload'] || (int)$existing[$key]->download !== $row['download']) {
                        $this->error('Previously imported record differs; reconcile before replacing.'); return 1;
                    }
                    unset($records[$key]);
                } else { $upload += $row['upload']; $download += $row['download']; }
            }
            $this->info(json_encode(['missing_rows'=>count($records), 'upload'=>$upload, 'download'=>$download, 'before'=>$cutoff]));
            if ($this->option('apply')) {
                foreach (array_chunk($records, 200) as $chunk) DB::table('v2_traffic_archive')->insertOrIgnore($chunk);
                $this->info('Historical totals imported; live records unchanged.');
            }
            return 0;
        }, 3);
    }
}
