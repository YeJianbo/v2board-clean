<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DashboardTrafficHistory
{
    public function readUsers(string $period, string $interval, ?int $userId = null, ?int $now = null): array
    {
        $now ??= time();
        $since = $period === 'today' ? strtotime('today', $now) : ($period === 'month' ? strtotime(date('Y-m-01', $now)) : 0);
        $table = $interval === 'hour' ? 'v2_stat_user_server_hour' : 'v2_stat_user_server';
        $query = DB::table($table)->where('record_at', '>=', $since)->where('record_at', '<=', $now);
        if ($userId !== null) $query->where('user_id', $userId);
        $dimensions = $userId === null ? ['user_id', 'record_at'] : ['user_id', 'record_at', 'server_type', 'server_id'];
        $rows = $query->select($dimensions)->selectRaw('SUM(u) as upload, SUM(d) as download')
            ->groupBy($dimensions)->orderBy('record_at')->cursor();

        $series = []; $users = []; $nodes = []; $total = 0; $dateLabels = [];
        $dateFormat = $interval === 'hour' ? 'Y-m-d H:00' : ($interval === 'month' ? 'Y-m' : 'Y-m-d');
        foreach ($rows as $row) {
            $id = (int) $row->user_id;
            $at = (int) $row->record_at;
            $date = $dateLabels[$at] ??= date($dateFormat, $at);
            $upload = (int) $row->upload; $download = (int) $row->download;
            $total += $upload + $download;
            if (!isset($series[$date])) $series[$date] = ['date' => $date, 'upload' => 0, 'download' => 0];
            $series[$date]['upload'] += $upload; $series[$date]['download'] += $download;
            if (!isset($users[$id])) $users[$id] = ['id' => $id, 'key' => 'user:' . $id, 'upload' => 0, 'download' => 0, 'points' => []];
            $users[$id]['upload'] += $upload; $users[$id]['download'] += $download;
            if (!isset($users[$id]['points'][$date])) $users[$id]['points'][$date] = ['date' => $date, 'upload' => 0, 'download' => 0];
            $users[$id]['points'][$date]['upload'] += $upload;
            $users[$id]['points'][$date]['download'] += $download;
            if ($userId !== null) {
                $type = strtolower((string) $row->server_type);
                $nodeId = (int) $row->server_id;
                $key = "$type:$nodeId";
                if (!isset($nodes[$key])) $nodes[$key] = ['id' => $nodeId, 'server_type' => $type, 'key' => $key, 'upload' => 0, 'download' => 0, 'points' => []];
                $nodes[$key]['upload'] += $upload; $nodes[$key]['download'] += $download;
                if (!isset($nodes[$key]['points'][$date])) $nodes[$key]['points'][$date] = ['date' => $date, 'upload' => 0, 'download' => 0];
                $nodes[$key]['points'][$date]['upload'] += $upload;
                $nodes[$key]['points'][$date]['download'] += $download;
            }
        }
        foreach ($users as &$user) { ksort($user['points']); $user['points'] = array_values($user['points']); }
        unset($user);
        foreach ($nodes as &$node) { ksort($node['points']); $node['points'] = array_values($node['points']); }
        unset($node);
        ksort($series);
        $users = array_values($users);
        usort($users, fn ($a, $b) => ($b['upload'] + $b['download']) <=> ($a['upload'] + $a['download']));
        $nodes = array_values($nodes);
        usort($nodes, fn ($a, $b) => ($b['upload'] + $b['download']) <=> ($a['upload'] + $a['download']));
        return ['series' => array_values($series), 'users' => $users, 'nodes' => $nodes, 'total' => $total];
    }

    public function read(string $period, string $interval, ?string $node = null, ?int $now = null): array
    {
        $now ??= time();
        $since = $period === 'today' ? strtotime('today', $now) : ($period === 'month' ? strtotime(date('Y-m-01', $now)) : 0);
        $days = []; $nodes = []; $undated = 0; $total = 0; $dateLabels = [];
        $dateFormat = $interval === 'month' ? 'Y-m' : 'Y-m-d';
        $add = function ($type, $id, $at, $u, $d) use (&$days, &$nodes, &$undated, &$total, &$dateLabels, $dateFormat, $since, $interval, $node) {
            if ($node && "$type:$id" !== $node) return;
            if (!$at && $since) return;
            if ($at && $at < $since) return;
            $u = (int) $u; $d = (int) $d; $total += $u + $d;
            if ($at) {
                if ($interval !== 'hour') {
                    $date = $dateLabels[$at] ??= date($dateFormat, $at);
                    if (!isset($days[$date])) $days[$date] = ['date' => $date, 'upload' => 0, 'download' => 0];
                    $days[$date]['upload'] += $u; $days[$date]['download'] += $d;
                }
            } else $undated += $u + $d;
            $key = $id ? "$type:$id" : 'unknown';
            if (!isset($nodes[$key])) $nodes[$key] = ['key' => $key, 'server_type' => $type, 'id' => $id, 'upload' => 0, 'download' => 0, 'points' => []];
            $nodes[$key]['upload'] += $u; $nodes[$key]['download'] += $d;
            if ($at && $interval !== 'hour') {
                if (!isset($nodes[$key]['points'][$date])) $nodes[$key]['points'][$date] = ['date' => $date, 'upload' => 0, 'download' => 0];
                $nodes[$key]['points'][$date]['upload'] += $u;
                $nodes[$key]['points'][$date]['download'] += $d;
            }
        };
        $live = DB::table('v2_stat_server')->where('record_at', '>=', $since);
        if ($node) {
            [$type, $id] = explode(':', $node, 2);
            $live->where('server_type', $type)->where('server_id', (int) $id);
        }
        $archive = DB::table('v2_traffic_archive');
        if ($since) {
            $archive->where('source', 'server_backup');
        } elseif ($node) {
            $archive->whereIn('source', ['v2_stat_server', 'server_backup']);
        } else {
            $archive->whereIn('source', ['v2_stat_server', 'server_backup', 'server_recovery']);
        }
        if ($node) {
            $archive->where(function ($query) use ($node, $since) {
                $query->where('source', 'server_backup')->where('subject', 'like', $node . ':%');
                if (!$since) $query->orWhere('source', 'v2_stat_server');
            });
        }
        // A single statement sees live and archived traffic at the same snapshot.
        $live->selectRaw("'live' as source, '' as subject, server_type, server_id, record_at, u as upload, d as download");
        $archive->selectRaw("source, subject, '' as server_type, 0 as server_id, 0 as record_at, upload, download");
        foreach ($live->unionAll($archive)->cursor() as $row) {
            if ($row->source === 'live') {
                $add($row->server_type, $row->server_id, (int) $row->record_at, $row->upload, $row->download);
                continue;
            }
            $at = 0; $type = ''; $id = 0;
            if ($row->source === 'server_backup' && preg_match('/^([^:]+):(\d+):(\d+)$/D', $row->subject, $match)) {
                [, $type, $id, $at] = $match;
            } elseif ($row->source === 'v2_stat_server') {
                $subject = json_decode($row->subject, true) ?: [];
                $type = $subject['server_type'] ?? ''; $id = $subject['server_id'] ?? 0;
            }
            $add($type, (int) $id, (int) $at, $row->upload, $row->download);
        }
        ksort($days);
        if ($interval === 'hour') {
            $days = [];
            // Hourly raw bytes are a breakdown, not another source of lifetime totals.
            $hours = DB::table('v2_stat_user_server_hour')->where('record_at', '>=', $since)
                ->where('record_at', '<=', $now);
            if ($node) {
                [$type, $id] = explode(':', $node);
                $hours->where('server_type', $type)->where('server_id', (int) $id);
            }
            $hours = $hours->selectRaw('record_at, server_type, server_id, SUM(u) as upload, SUM(d) as download')
                ->groupBy('record_at', 'server_type', 'server_id')->orderBy('record_at')->cursor();
            $hourLabels = [];
            foreach ($hours as $hour) {
                $at = (int) $hour->record_at;
                $date = $hourLabels[$at] ??= date('Y-m-d H:00', $at);
                $point = ['date' => $date, 'upload' => (int) $hour->upload, 'download' => (int) $hour->download];
                if (!isset($days[$date])) $days[$date] = ['date' => $date, 'upload' => 0, 'download' => 0];
                $days[$date]['upload'] += $point['upload']; $days[$date]['download'] += $point['download'];
                $key = $hour->server_type . ':' . $hour->server_id;
                if (!isset($nodes[$key])) $nodes[$key] = ['key' => $key, 'server_type' => $hour->server_type, 'id' => $hour->server_id, 'upload' => 0, 'download' => 0, 'points' => []];
                $nodes[$key]['points'][$date] = $point;
            }
        }
        foreach ($nodes as &$item) { ksort($item['points']); $item['points'] = array_values($item['points']); }
        unset($item);
        usort($nodes, fn ($a, $b) => ($b['upload'] + $b['download']) <=> ($a['upload'] + $a['download']));
        return ['series' => array_values($days), 'nodes' => array_values($nodes), 'total' => $total, 'undated' => $undated];
    }
}
