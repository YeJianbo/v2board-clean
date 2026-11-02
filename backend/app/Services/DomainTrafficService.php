<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DomainTrafficService
{
    public function ingest(int $nodeId, array $batch): void
    {
        DB::transaction(function () use ($nodeId, $batch) {
            if (!DB::table('v2_domain_traffic_receipt')->insertOrIgnore([
                'node_id' => $nodeId, 'batch_id' => $batch['batch_id'], 'created_at' => time(),
            ])) {
                return;
            }
            $day = (int) (floor($batch['recorded_at'] / 86400) * 86400);
            $hour = (int) (floor($batch['recorded_at'] / 3600) * 3600);
            $records = [];
            foreach ($batch['records'] as $record) {
                $domain = strtolower(rtrim($record['domain'], '.'));
                if (filter_var($domain, FILTER_VALIDATE_IP) || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($domain, '.')) {
                    continue;
                }
                if (!isset($records[$domain])) $records[$domain] = ['domain' => $domain, 'upload' => 0, 'download' => 0];
                $records[$domain]['upload'] += (int) $record['upload'];
                $records[$domain]['download'] += (int) $record['download'];
            }
            // Stable lock order and bounded statements keep large reports inexpensive.
            ksort($records);
            foreach (['v2_domain_traffic' => ['day', $day], 'v2_domain_traffic_hour' => ['hour', $hour]] as $table => [$column, $bucket]) {
                $mysql = DB::connection()->getDriverName() === 'mysql';
                $updates = [];
                foreach (['upload', 'download'] as $field) {
                    $incoming = $mysql ? "VALUES({$field})" : "excluded.{$field}";
                    $updates[$field] = DB::raw("{$table}.{$field} + {$incoming}");
                }
                foreach (array_chunk($records, 100) as $chunk) {
                    $rows = array_map(fn ($record) => ['node_id' => $nodeId, $column => $bucket] + $record, $chunk);
                    DB::table($table)->upsert($rows, ['node_id', $column, 'domain'], $updates);
                }
            }
        }, 3);
    }

    public function ranking(int $days, string $search = '', ?int $nodeId = null): array
    {
        $until = (int) (floor(time() / 86400) * 86400) + 86400;
        $since = $until - $days * 86400;
        $result = $this->rankingBuckets(['column' => 'day', 'table' => 'v2_domain_traffic', 'since' => $since, 'until' => $until], $search, $nodeId);
        // Preserve the legacy collection/object shape while sharing root-domain aggregation.
        $result['rows'] = collect($result['rows'])->map(fn ($row) => (object) $row);
        $result['days'] = $days;
        return $result;
    }

    public function rankingRange(int $startAt, int $endAt, string $search = '', ?int $nodeId = null): array
    {
        return $this->rankingBuckets($this->rangeBuckets($startAt, $endAt), $search, $nodeId);
    }

    public function cachedRankingRange(int $startAt, int $endAt, string $search = '', ?int $nodeId = null): array
    {
        $range = $this->rangeBuckets($startAt, $endAt);
        $key = 'domain-traffic:rank:v5:'.$range['column'].':'.$range['since'].':'.$range['until'].':'.($nodeId ?? 'all').':'.sha1($search);
        return Cache::remember($key, 15, fn () => $this->rankingBuckets($range, $search, $nodeId));
    }

    private function rangeBuckets(int $startAt, int $endAt): array
    {
        $hourly = $endAt - $startAt <= 31 * 86400 && $startAt >= time() - TrafficRetention::days('domain_hour') * 86400;
        $column = $hourly ? 'hour' : 'day';
        $table = $hourly ? 'v2_domain_traffic_hour' : 'v2_domain_traffic';
        $step = $hourly ? 3600 : 86400;
        $since = (int) (floor($startAt / $step) * $step);
        $until = (int) (ceil($endAt / $step) * $step);
        return compact('column', 'table', 'since', 'until');
    }

    private function rankingBuckets(array $range, string $search, ?int $nodeId): array
    {
        ['column' => $column, 'table' => $table, 'since' => $since, 'until' => $until] = $range;
        $query = DB::table($table)->where($column, '>=', $since)->where($column, '<', $until);
        if ($nodeId) $query->where('node_id', $nodeId);
        if ($search !== '') {
            // A matching root must occur in the full domain; still verify the root below.
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $query->whereRaw("domain LIKE ? ESCAPE '!'", [$pattern]);
        }
        $roots = new RegistrableDomain();
        $grouped = []; $total = 0;
        foreach ($query->selectRaw('domain, SUM(upload) as upload, SUM(download) as download')
            ->groupBy('domain')->cursor() as $record) {
            $root = $roots->root($record->domain);
            if ($search !== '' && !str_contains($root, $search)) continue;
            if (!isset($grouped[$root])) $grouped[$root] = ['domain' => $root, 'upload' => 0, 'download' => 0, 'total' => 0];
            $upload = (int) $record->upload; $download = (int) $record->download;
            $grouped[$root]['upload'] += $upload;
            $grouped[$root]['download'] += $download;
            $grouped[$root]['total'] += $upload + $download;
            $total += $upload + $download;
        }
        return ['rows' => $this->topDomains($grouped), 'total' => $total, 'since' => $since, 'until' => $until,
            'resolution' => $column];
    }

    private function topDomains(array $grouped): array
    {
        $leaders = new class extends \SplPriorityQueue {
            public function compare($left, $right): int
            {
                return ($left[0] <=> $right[0]) ?: strcmp($left[1], $right[1]);
            }
        };
        $leaders->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        foreach ($grouped as $row) {
            if ($leaders->count() === 100) {
                $worst = $leaders->top();
                if ($row['total'] < $worst['total']
                    || ($row['total'] === $worst['total'] && strcmp($row['domain'], $worst['domain']) >= 0)) continue;
            }
            // Evict lower traffic first, then the alphabetically later equal-traffic domain.
            $leaders->insert($row, [-$row['total'], $row['domain']]);
            if ($leaders->count() > 100) $leaders->extract();
        }
        $rows = [];
        while (!$leaders->isEmpty()) $rows[] = $leaders->extract();
        return array_reverse($rows);
    }

    public function history(string $domain, int $days, ?int $nodeId = null): array
    {
        $hourly = $days === 1;
        $column = $hourly ? 'hour' : 'day';
        $table = $hourly ? 'v2_domain_traffic_hour' : 'v2_domain_traffic';
        $step = $hourly ? 3600 : 86400;
        $now = time();
        $since = (int) (floor($now / 86400) * 86400) - ($days - 1) * 86400;
        $until = (int) (floor($now / $step) * $step) + $step;
        return $this->historyBuckets($domain, compact('column', 'table', 'since', 'until'), $nodeId) + ['days' => $days];
    }

    public function historyRange(string $domain, int $startAt, int $endAt, ?int $nodeId = null): array
    {
        return $this->historyBuckets($domain, $this->rangeBuckets($startAt, $endAt), $nodeId);
    }

    private function historyBuckets(string $domain, array $range, ?int $nodeId): array
    {
        ['column' => $column, 'table' => $table, 'since' => $since, 'until' => $until] = $range;
        $query = DB::table($table)->where(function ($query) use ($domain) {
            $query->where('domain', $domain)->orWhere('domain', 'like', '%.' . str_replace(['%', '_'], ['\\%', '\\_'], $domain));
        })->where($column, '>=', $since)->where($column, '<', $until);
        if ($nodeId) $query->where('node_id', $nodeId);
        // Derive totals and both charts from one read so concurrent reports cannot split their snapshot.
        $nodes = []; $points = []; $domainMatches = [];
        $roots = new RegistrableDomain();
        $rootOnly = $roots->root($domain) === $domain;
        foreach ($query->selectRaw("domain, node_id, {$column} as bucket, SUM(upload) as upload, SUM(download) as download")
            ->groupBy('domain', 'node_id', $column)->orderBy($column)->orderBy('node_id')->cursor() as $row) {
            // Nested public suffixes can belong to a different root despite sharing this suffix.
            $domainMatches[$row->domain] ??= !$rootOnly || $roots->root($row->domain) === $domain;
            if (!$domainMatches[$row->domain]) continue;
            $id = (int) $row->node_id; $bucket = (int) $row->bucket;
            $upload = (int) $row->upload; $download = (int) $row->download;
            if (!isset($nodes[$id])) {
                $nodes[$id] = ['id' => $id, 'upload' => 0, 'download' => 0, 'total' => 0, 'points' => []];
            }
            $nodes[$id]['upload'] += $upload;
            $nodes[$id]['download'] += $download;
            $nodes[$id]['total'] += $upload + $download;
            if (!isset($nodes[$id]['points'][$bucket])) {
                $nodes[$id]['points'][$bucket] = ['bucket' => $bucket, 'upload' => 0, 'download' => 0];
            }
            $nodes[$id]['points'][$bucket]['upload'] += $upload;
            $nodes[$id]['points'][$bucket]['download'] += $download;
            if (!isset($points[$bucket])) $points[$bucket] = (object) ['bucket' => $bucket, 'upload' => 0, 'download' => 0];
            $points[$bucket]->upload += $upload;
            $points[$bucket]->download += $download;
        }
        $names = \App\Models\ServerV2node::whereIn('id', array_keys($nodes))->pluck('name', 'id');
        foreach ($nodes as $id => &$node) {
            $node['name'] = $names[$id] ?? '已删除节点 #' . $id;
            $node['points'] = array_values($node['points']);
        }
        unset($node);
        uasort($nodes, fn ($a, $b) => ($b['total'] <=> $a['total']) ?: ($a['id'] <=> $b['id']));
        return ['domain' => $domain, 'resolution' => $column, 'points' => collect(array_values($points)),
            'nodes' => array_values($nodes),
            'since' => $since, 'until' => $until];
    }

    public function prune(): int
    {
        $removed = app(TrafficRetention::class)->prune('v2_domain_traffic', 'day',
            (int) (floor(time() / 86400) * 86400) - TrafficRetention::days('domain_daily') * 86400,
            ['node_id', 'day', 'domain']);
        foreach (['v2_domain_traffic_hour' => 'hour', 'v2_domain_traffic_receipt' => 'created_at'] as $table => $column) {
            $retention = $table === 'v2_domain_traffic_hour' ? TrafficRetention::days('domain_hour') : 31;
            $cutoff = $table === 'v2_domain_traffic_hour' ? (int) (floor(time() / 3600) * 3600) - $retention * 86400 : time() - $retention * 86400;
            do {
                $count = DB::table($table)->where($column, '<', $cutoff)->orderBy($column)->limit(5000)->delete();
                $removed += $count;
            } while ($count === 5000);
        }
        return $removed;
    }
}
