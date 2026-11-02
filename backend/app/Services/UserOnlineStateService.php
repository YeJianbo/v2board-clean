<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class UserOnlineStateService
{
    public static function updateNode(int $userId, string $nodeKey, array $ips, int $updateAt, bool $distinctIps): void
    {
        $key = 'ALIVE_IP_USER_' . $userId;
        // Keep one report interval plus the existing scheduling/network allowance.
        $freshnessSeconds = max(100, max(1, (int) config('v2board.server_push_interval', 60)) + 40);
        // Re-read inside the per-user lock: a prefetched snapshot can erase another node's report.
        Cache::lock('online-state:update:' . $userId, 60)->block(2, static function () use ($key, $nodeKey, $ips, $updateAt, $distinctIps, $freshnessSeconds): void {
            $state = Cache::get($key);
            if (!is_array($state)) $state = [];
            $state[$nodeKey] = ['aliveips' => $ips, 'lastupdateAt' => $updateAt];
            $unique = [];
            $count = 0;
            foreach ($state as $node => $entry) {
                if ($node === 'alive_ip') continue;
                if (!is_array($entry) || !is_numeric($entry['lastupdateAt'] ?? null)
                    || $updateAt - (int) $entry['lastupdateAt'] > $freshnessSeconds) {
                    unset($state[$node]);
                    continue;
                }
                $entries = $entry['aliveips'] ?? [];
                if (is_string($entries)) $entries = [$entries];
                if (!is_array($entries)) $entries = [];
                $entries = array_values(array_filter($entries, static fn ($ip) => is_string($ip) && $ip !== ''));
                $state[$node]['aliveips'] = $entries;
                if ($distinctIps) {
                    foreach ($entries as $ip) $unique[explode('_', $ip)[0]] = true;
                } else {
                    $count += count($entries);
                }
            }
            $state['alive_ip'] = $distinctIps ? count($unique) : $count;
            Cache::put($key, $state, $freshnessSeconds + 20);
        });
    }

    public static function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds) return [];
        $cached = Cache::many(array_map(static fn ($id) => 'ALIVE_IP_USER_'.$id, $userIds));
        $states = [];
        foreach ($userIds as $id) $states[$id] = self::parse($cached['ALIVE_IP_USER_'.$id] ?? null);
        return $states;
    }

    public static function count(mixed $state): int
    {
        if (!is_array($state)) return 0;
        $rawCount = $state['alive_ip'] ?? 0;
        return is_scalar($rawCount) && is_numeric($rawCount) ? max(0, (int) $rawCount) : 0;
    }

    public static function parse(mixed $state): array
    {
        $count = self::count($state); $ips = [];
        if (is_array($state)) {
            foreach ($state as $nodeKey => $data) {
                if ($nodeKey === 'alive_ip' || !is_array($data)) continue;
                $entries = $data['aliveips'] ?? [];
                if (is_string($entries)) $entries = [$entries];
                if (!is_array($entries)) continue;
                foreach ($entries as $entry) {
                    if (!is_string($entry)) continue;
                    $ip = explode('_', $entry)[0];
                    if ($ip !== '') $ips[] = $ip.'_'.$nodeKey;
                }
            }
        }
        return ['count' => $count, 'ips' => implode(', ', $ips)];
    }
}
