<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TrafficRetention
{
    public const DEFAULTS = ['minute' => 31, 'hour' => 366, 'daily' => 1095, 'domain_hour' => 2, 'domain_daily' => 31];

    public static function days(string $kind): int
    {
        return max(1, min(3650, (int) admin_setting('traffic_retention_' . $kind, self::DEFAULTS[$kind])));
    }

    public static function settings(): array
    {
        return array_map(fn ($kind) => self::days($kind), array_combine(array_keys(self::DEFAULTS), array_keys(self::DEFAULTS)));
    }

    // Archive and deletion share a transaction: a retry cannot double-count expired rows.
    public function prune(string $table, string $timeColumn, int $cutoff, array $keys, int $batch = 500): int
    {
        $deleted = 0;
        do {
            $count = DB::transaction(function () use ($table, $timeColumn, $cutoff, $keys, $batch) {
                $query = DB::table($table)->where($timeColumn, '<', $cutoff)->orderBy($timeColumn);
                foreach ($keys as $key) if ($key !== $timeColumn) $query->orderBy($key);
                $rows = $query->limit($batch)->lockForUpdate()->get();
                if ($rows->isEmpty()) return 0;
                $domain = $table === 'v2_domain_traffic';
                $grouped = [];
                foreach ($rows as $row) {
                    // Expired domain detail only contributes to lifetime node totals.
                    $dimensions = $domain ? [(int) $row->node_id] : array_filter([
                        'user_id' => $row->user_id ?? null, 'server_type' => $row->server_type ?? null,
                        'server_id' => $row->server_id ?? null, 'server_rate' => isset($row->server_rate) ? (string) $row->server_rate : null,
                    ], fn ($value) => $value !== null);
                    $subject = json_encode($dimensions, JSON_UNESCAPED_UNICODE);
                    if (!isset($grouped[$subject])) $grouped[$subject] = ['source' => $table, 'subject' => $subject, 'upload' => 0, 'download' => 0];
                    $grouped[$subject]['upload'] += (int) ($domain ? $row->upload : $row->u);
                    $grouped[$subject]['download'] += (int) ($domain ? $row->download : $row->d);
                }
                ksort($grouped);
                $mysql = DB::connection()->getDriverName() === 'mysql';
                $updates = [];
                foreach (['upload', 'download'] as $field) {
                    $incoming = $mysql ? "VALUES({$field})" : "excluded.{$field}";
                    $updates[$field] = DB::raw("v2_traffic_archive.{$field} + {$incoming}");
                }
                foreach (array_chunk($grouped, 100) as $chunk) DB::table('v2_traffic_archive')->upsert($chunk, ['source', 'subject'], $updates);
                $removed = 0;
                foreach ($rows->chunk(100) as $chunk) {
                    if (count($keys) === 1) {
                        $removed += DB::table($table)->whereIn($keys[0], $chunk->pluck($keys[0])->all())->delete();
                        continue;
                    }
                    $removed += DB::table($table)->where(function ($delete) use ($chunk, $keys) {
                        foreach ($chunk as $row) $delete->orWhere(function ($item) use ($keys, $row) {
                            foreach ($keys as $key) $item->where($key, $row->$key);
                        });
                    })->delete();
                }
                return $removed;
            }, 3);
            $deleted += $count;
        } while ($count === $batch);
        return $deleted;
    }

    public function serverTotal(): object
    {
        // Aggregate each source before UNION to avoid materializing every traffic row.
        $live = DB::table('v2_stat_server')->selectRaw('COALESCE(SUM(u),0) as upload, COALESCE(SUM(d),0) as download, COALESCE(SUM(u + d),0) as total');
        $archive = DB::table('v2_traffic_archive')->whereIn('source', ['v2_stat_server', 'server_recovery', 'server_backup'])
            ->selectRaw('COALESCE(SUM(upload),0) as upload, COALESCE(SUM(download),0) as download, COALESCE(SUM(upload + download),0) as total');
        return DB::query()->fromSub($live->unionAll($archive), 'traffic')
            ->selectRaw('COALESCE(SUM(upload),0) as upload, COALESCE(SUM(download),0) as download, COALESCE(SUM(total),0) as total')->first();
    }

    public function domainTotal(?int $nodeId = null): int
    {
        $live = DB::table('v2_domain_traffic');
        $archive = DB::table('v2_traffic_archive')->where('source', 'v2_domain_traffic');
        if ($nodeId) {
            $live->where('node_id', $nodeId);
            $archive->where(function ($query) use ($nodeId) {
                $query->where('subject', json_encode([$nodeId]))
                    ->orWhere('subject', 'like', '[' . $nodeId . ',%')
                    ->orWhere('subject', 'like', '["' . $nodeId . '",%');
            });
        }
        $live->selectRaw('COALESCE(SUM(upload + download),0) as total');
        $archive->selectRaw('COALESCE(SUM(upload + download),0) as total');
        return (int) DB::query()->fromSub($live->unionAll($archive), 'traffic')
            ->selectRaw('COALESCE(SUM(total),0) as total')->value('total');
    }
}
