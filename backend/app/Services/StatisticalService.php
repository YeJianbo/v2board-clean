<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StatisticalService
{
    protected $startAt;
    protected $endAt;
    protected $statServerKey;
    protected $statUserKey;
    protected $redis;
    protected $batchService;
    private ?array $trafficCommands = null;
    private array $trafficExpirations = [];

    public function __construct()
    {
        $this->redis = Redis::connection();
        $this->batchService = app(ProcessingBatchService::class);
    }

    public function setStartAt($timestamp)
    {
        $this->startAt = (int) $timestamp;
        $this->statServerKey = "stat_server_{$this->startAt}";
        $this->statUserKey = "stat_user_{$this->startAt}";
    }

    public function setEndAt($timestamp)
    {
        $this->endAt = (int) $timestamp;
    }

    public function setServerStats()
    {
        $this->ensureStartAt();
    }

    public function setUserStats()
    {
        $this->ensureStartAt();
    }

    private function ensureStartAt(): void
    {
        if (!$this->startAt) {
            $this->setStartAt(strtotime(date('Y-m-d')));
        }
    }

    private function statUserServerKey(string $table, int $recordAt): string
    {
        return "{$table}_{$recordAt}";
    }

    private function encodeMember(array $parts): string
    {
        return implode('|', array_map(static fn ($part) => str_replace('|', '%7C', (string) $part), $parts));
    }

    private function decodeMember(string $member): array
    {
        return array_map(static fn ($part) => str_replace('%7C', '|', $part), explode('|', $member));
    }

    public function withTrafficPipeline(callable $callback): void
    {
        if ($this->trafficCommands !== null) {
            throw new \LogicException('Traffic pipelines cannot be nested.');
        }

        $this->trafficCommands = [];
        try {
            $callback();
            $commands = $this->trafficCommands;
            foreach ($this->trafficExpirations as $key => $seconds) {
                $commands[] = ['expire', [$key, $seconds]];
            }
        } finally {
            $this->trafficCommands = null;
            $this->trafficExpirations = [];
        }

        if (!$commands) {
            return;
        }

        try {
            $results = $this->redis->pipeline(static function ($pipeline) use ($commands): void {
                foreach ($commands as [$method, $arguments]) {
                    $pipeline->{$method}(...$arguments);
                }
            });
            if (!is_array($results) || count($results) !== count($commands)) {
                throw new \RuntimeException('Traffic pipeline returned incomplete results.');
            }
            foreach ($commands as $index => [$method]) {
                if ($method !== 'expire' && !is_numeric($results[$index])) {
                    throw new \RuntimeException('Traffic pipeline increment failed.');
                }
            }
        } catch (\Throwable $exception) {
            // Discard any client-side pipeline state; already sent writes are not replayed here.
            try {
                $this->redis->disconnect();
            } catch (\Throwable $ignored) {
                // Preserve the write error even when the broken socket cannot be closed.
            }
            foreach (Redis::connections() as $name => $connection) {
                if ($connection === $this->redis) {
                    Redis::purge($name);
                }
            }
            throw $exception;
        }
    }

    private function writeTrafficCommand(string $method, array $arguments): void
    {
        if ($this->trafficCommands !== null) {
            $this->trafficCommands[] = [$method, $arguments];
            return;
        }
        $this->redis->{$method}(...$arguments);
    }

    private function expireTrafficKey(string $key): void
    {
        if ($this->trafficCommands !== null) {
            $this->trafficExpirations[$key] = 86400 * 3;
            return;
        }
        $this->redis->expire($key, 86400 * 3);
    }

    public function statUserTraffic($rate, $userId, int $u, int $d): void
    {
        $this->writeTrafficCommand('hincrby', ['v2board_upload_traffic', $userId, (int) ($u * $rate)]);
        $this->writeTrafficCommand('hincrby', ['v2board_download_traffic', $userId, (int) ($d * $rate)]);
        $this->statUser($rate, (int) $userId, $u, $d);
    }

    public static function getFinancialSummary(int $now, bool $withComparisons = false): array
    {
        $monthStart = strtotime(date('Y-m-01', $now));
        $lastMonthStart = strtotime('-1 month', $monthStart);
        $todayStart = strtotime(date('Y-m-d', $now));
        $startAt = $withComparisons ? strtotime('-2 month', $monthStart) : $lastMonthStart;

        $incomeSql = [
            'SUM(CASE WHEN created_at >= ? THEN total_amount ELSE 0 END) AS today_income',
            'SUM(CASE WHEN created_at >= ? THEN total_amount ELSE 0 END) AS current_month_income',
            'SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total_amount ELSE 0 END) AS last_month_income',
        ];
        $incomeBindings = [$todayStart, $monthStart, $lastMonthStart, $monthStart];
        $payoutSql = [
            'SUM(CASE WHEN created_at >= ? THEN get_amount ELSE 0 END) AS current_month_payout',
            'SUM(CASE WHEN created_at >= ? AND created_at < ? THEN get_amount ELSE 0 END) AS last_month_payout',
        ];
        $payoutBindings = [$monthStart, $lastMonthStart, $monthStart];
        if ($withComparisons) {
            $incomeSql[] = 'SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total_amount ELSE 0 END) AS yesterday_income';
            $incomeSql[] = 'SUM(CASE WHEN created_at < ? THEN total_amount ELSE 0 END) AS two_months_ago_income';
            array_push($incomeBindings, strtotime('-1 day', $todayStart), $todayStart, $lastMonthStart);
            $payoutSql[] = 'SUM(CASE WHEN created_at < ? THEN get_amount ELSE 0 END) AS two_months_ago_payout';
            $payoutBindings[] = $lastMonthStart;
        }

        // Static reads do not initialize this service's Redis traffic pipeline.
        $income = Order::where('created_at', '>=', $startAt)->where('created_at', '<', $now)
            ->whereNotIn('status', [0, 2])->selectRaw(implode(', ', $incomeSql), $incomeBindings)->first();
        $payout = CommissionLog::where('created_at', '>=', $startAt)->where('created_at', '<', $now)
            ->selectRaw(implode(', ', $payoutSql), $payoutBindings)->first();
        return array_map(static fn ($value) => $value ?: 0, array_merge($income->getAttributes(), $payout->getAttributes()));
    }

    public function generateStatData(): array
    {
        $startAt = $this->startAt;
        $endAt = $this->endAt;
        if (!$startAt || !$endAt) {
            $startAt = strtotime(date('Y-m-d'));
            $endAt = strtotime('+1 day', $startAt);
        }
        $orders = Order::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->selectRaw('COUNT(*) AS order_count, SUM(total_amount) AS order_total')
            ->first();
        $paid = Order::where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereNotIn('status', [0, 2])
            ->selectRaw('COUNT(*) AS paid_count, SUM(total_amount) AS paid_total')
            ->first();
        $commissions = CommissionLog::where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->selectRaw('COUNT(*) AS commission_count, SUM(get_amount) AS commission_total')
            ->first();
        $users = User::withoutConnectivityTests()->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->selectRaw('COUNT(*) AS register_count, COUNT(invite_user_id) AS invite_count')
            ->first();
        $data = [
            'order_count' => (int) $orders->order_count,
            'order_total' => $orders->order_total ?? 0,
            'paid_count' => (int) $paid->paid_count,
            'paid_total' => $paid->paid_total ?? 0,
            'commission_count' => (int) $commissions->commission_count,
            'commission_total' => $commissions->commission_total ?? 0,
            'register_count' => (int) $users->register_count,
            'invite_count' => (int) $users->invite_count,
        ];
        $data['transfer_used_total'] = StatServer::where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->select(DB::raw('SUM(u) + SUM(d) as total'))
            ->value('total') ?? 0;
        return $data;
    }

    public function statServer($serverId, $serverType, $u, $d): void
    {
        $this->ensureStartAt();
        $serverId = (int) $serverId;
        $serverType = strtolower(trim((string) $serverType));
        if ($serverId <= 0 || $serverType === '') {
            return;
        }

        $this->writeTrafficCommand('zincrby', [$this->statServerKey, (int) $u, $this->encodeMember([$serverType, $serverId, 'u'])]);
        $this->writeTrafficCommand('zincrby', [$this->statServerKey, (int) $d, $this->encodeMember([$serverType, $serverId, 'd'])]);
        $this->expireTrafficKey($this->statServerKey);
    }

    public function statUser($rate, $userId, $u, $d): void
    {
        $this->ensureStartAt();
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        $rate = number_format((float) $rate, 2, '.', '');
        $this->writeTrafficCommand('zincrby', [$this->statUserKey, (int) $u, $this->encodeMember([$rate, $userId, 'u'])]);
        $this->writeTrafficCommand('zincrby', [$this->statUserKey, (int) $d, $this->encodeMember([$rate, $userId, 'd'])]);
        $this->expireTrafficKey($this->statUserKey);
    }

    public function statUserServer(string $table, int $recordAt, int $userId, int $serverId, string $serverType, $rate, $u, $d): void
    {
        if ($recordAt <= 0 || $userId <= 0 || $serverId <= 0) {
            return;
        }

        $serverType = strtolower(trim($serverType));
        if ($serverType === '') {
            return;
        }

        $key = $this->statUserServerKey($table, $recordAt);
        $rate = number_format((float) $rate, 2, '.', '');
        $this->writeTrafficCommand('zincrby', [$key, (int) $u, $this->encodeMember([$userId, $serverId, $serverType, $rate, 'u'])]);
        $this->writeTrafficCommand('zincrby', [$key, (int) $d, $this->encodeMember([$userId, $serverId, $serverType, $rate, 'd'])]);
        $this->expireTrafficKey($key);
    }

    public function getStatUserByUserID($userId): array
    {
        $this->ensureStartAt();
        $stats = [];
        $statsUser = $this->redis->zrange($this->statUserKey, 0, -1, true);
        foreach ($statsUser as $member => $value) {
            [$rate, $uid, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if ((int) $uid !== (int) $userId || !in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => 0,
                'd' => 0,
                'user_id' => (int) $uid,
            ];
            $stats[$key][$type] += (float) $value;
        }
        return array_values($stats);
    }

    public function getStatUser(): array
    {
        $this->ensureStartAt();
        $stats = [];
        $statsUser = $this->redis->zrange($this->statUserKey, 0, -1, true);
        foreach ($statsUser as $member => $value) {
            [$rate, $uid, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => 0,
                'd' => 0,
                'user_id' => (int) $uid,
            ];
            $stats[$key][$type] += (float) $value;
        }
        return array_values($stats);
    }

    public function getStatServer(): array
    {
        $this->ensureStartAt();
        $stats = [];
        $statsServer = $this->redis->zrange($this->statServerKey, 0, -1, true);
        foreach ($statsServer as $member => $value) {
            [$serverType, $serverId, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$serverType}_{$serverId}";
            $stats[$key] = $stats[$key] ?? [
                'server_id' => (int) $serverId,
                'server_type' => $serverType,
                'u' => 0,
                'd' => 0,
            ];
            $stats[$key][$type] += (float) $value;
        }
        return array_values($stats);
    }

    public function clearStatUser(): void
    {
        $this->ensureStartAt();
        $this->redis->del($this->statUserKey);
    }

    public function clearStatServer(): void
    {
        $this->ensureStartAt();
        $this->redis->del($this->statServerKey);
    }

    public function flushStatServer(int $recordAt, string $recordType = 'd'): void
    {
        $this->setStartAt($recordAt);
        [$batch, $raw] = $this->batchService->move($this->statServerKey, 'zset');
        if (!$raw) {
            $this->batchService->complete($batch);
            return;
        }

        $stats = $this->formatStatServerRows($raw);
        if (!$stats) {
            $this->batchService->complete($batch);
            return;
        }

        // On failure, recovery checks the receipt before restoring an uncertain commit.
        DB::transaction(function () use ($stats, $recordAt, $recordType, $batch) {
            $this->batchService->record($batch['batch_id'], 'stat_server');
            $this->upsertStatServerBatch($stats, $recordAt, $recordType, time());
        }, 3);

        $this->completeCommittedBatch($batch);
    }

    public function flushStatUser(int $recordAt, string $recordType = 'd'): void
    {
        $this->setStartAt($recordAt);
        [$batch, $raw] = $this->batchService->move($this->statUserKey, 'zset');
        if (!$raw) {
            $this->batchService->complete($batch);
            return;
        }

        $stats = $this->formatStatUserRows($raw);
        if (!$stats) {
            $this->batchService->complete($batch);
            return;
        }

        DB::transaction(function () use ($stats, $recordAt, $recordType, $batch) {
            $this->batchService->record($batch['batch_id'], 'stat_user');
            $this->upsertStatUserBatch($stats, $recordAt, $recordType, time());
        }, 3);

        $this->completeCommittedBatch($batch);
    }

    public function flushStatUserServer(string $table, int $recordAt, string $recordType): void
    {
        $key = $this->statUserServerKey($table, $recordAt);
        [$batch, $raw] = $this->batchService->move($key, 'zset');
        if (!$raw) {
            $this->batchService->complete($batch);
            return;
        }

        $stats = [];
        foreach ($raw as $member => $value) {
            [$userId, $serverId, $serverType, $rate, $type] = array_pad($this->decodeMember((string) $member), 5, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $statKey = "{$userId}|{$serverId}|{$serverType}|{$rate}";
            $stats[$statKey] = $stats[$statKey] ?? [
                'user_id' => (int) $userId,
                'server_id' => (int) $serverId,
                'server_type' => (string) $serverType,
                'server_rate' => (string) $rate,
                'u' => 0,
                'd' => 0,
            ];
            $stats[$statKey][$type] += (float) $value;
        }

        if (!$stats) {
            $this->batchService->complete($batch);
            return;
        }

        DB::transaction(function () use ($table, $recordAt, $recordType, $stats, $batch) {
            $this->batchService->record($batch['batch_id'], $table);
            $this->upsertStatUserServerBatch($table, array_values($stats), $recordAt, $recordType, time());
        }, 3);

        $this->completeCommittedBatch($batch);
    }

    private function completeCommittedBatch(?array $batch): void
    {
        try {
            $this->batchService->complete($batch);
        } catch (\Throwable $e) {
            Log::warning('统计批次已入账但 Redis 清理失败，等待下次恢复清理', [
                'batch_id' => $batch['batch_id'] ?? null,
                'exception' => $e,
            ]);
        }
    }

    public function applyTrafficStatistics(array $statistics): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Traffic statistics require an active transaction.');
        }
        $now = time();
        foreach ($statistics as $table => $buckets) {
            ksort($buckets, SORT_NUMERIC);
            foreach ($buckets as $recordAt => $bucket) {
                $rows = array_values($bucket['rows']);
                if ($table === 'v2_stat_server') {
                    $this->upsertStatServerBatch($rows, $recordAt, $bucket['record_type'], $now);
                } elseif ($table === 'v2_stat_user') {
                    $this->upsertStatUserBatch($rows, $recordAt, $bucket['record_type'], $now);
                } else {
                    $this->upsertStatUserServerBatch($table, $rows, $recordAt, $bucket['record_type'], $now);
                }
            }
        }
    }

    private function formatStatServerRows(array $raw): array
    {
        $stats = [];
        foreach ($raw as $member => $value) {
            [$serverType, $serverId, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$serverType}_{$serverId}";
            $stats[$key] = $stats[$key] ?? [
                'server_id' => (int) $serverId,
                'server_type' => $serverType,
                'u' => 0,
                'd' => 0,
            ];
            $stats[$key][$type] += (float) $value;
        }

        return array_values($stats);
    }

    private function formatStatUserRows(array $raw): array
    {
        $stats = [];
        foreach ($raw as $member => $value) {
            [$rate, $uid, $type] = array_pad($this->decodeMember((string) $member), 3, null);
            if (!in_array($type, ['u', 'd'], true)) {
                continue;
            }
            $key = "{$rate}_{$uid}";
            $stats[$key] = $stats[$key] ?? [
                'record_at' => $this->startAt,
                'server_rate' => $rate,
                'u' => 0,
                'd' => 0,
                'user_id' => (int) $uid,
            ];
            $stats[$key][$type] += (float) $value;
        }

        return array_values($stats);
    }

    private function upsertStatServerBatch(array $stats, int $recordAt, string $recordType, int $now): void
    {
        $rows = [];
        foreach ($stats as $row) {
            if ((float)$row['u'] <= 0 && (float)$row['d'] <= 0) {
                continue;
            }
            $rows[] = [
                (int)$row['server_id'],
                (string)$row['server_type'],
                (int)$row['u'],
                (int)$row['d'],
                $recordType,
                $recordAt,
                $now,
                $now,
            ];
        }

        $this->incrementUpsert(
            'v2_stat_server',
            ['server_id', 'server_type', 'u', 'd', 'record_type', 'record_at', 'created_at', 'updated_at'],
            $rows
        );
    }

    private function upsertStatUserBatch(array $stats, int $recordAt, string $recordType, int $now): void
    {
        $rows = [];
        foreach ($stats as $row) {
            if ((float)$row['u'] <= 0 && (float)$row['d'] <= 0) {
                continue;
            }
            $rows[] = [
                (int)$row['user_id'],
                (string)$row['server_rate'],
                (int)$row['u'],
                (int)$row['d'],
                $recordType,
                $recordAt,
                $now,
                $now,
            ];
        }

        $this->incrementUpsert(
            'v2_stat_user',
            ['user_id', 'server_rate', 'u', 'd', 'record_type', 'record_at', 'created_at', 'updated_at'],
            $rows
        );
    }

    private function upsertStatUserServerBatch(
        string $table,
        array $stats,
        int $recordAt,
        string $recordType,
        int $now
    ): void
    {
        $allowedTables = [
            'v2_stat_user_server',
            'v2_stat_user_server_hour',
            'v2_stat_user_server_minute',
        ];
        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException('Unsupported statistics table');
        }

        $rows = [];
        foreach ($stats as $row) {
            if ((float)$row['u'] <= 0 && (float)$row['d'] <= 0) {
                continue;
            }
            $rows[] = [
                (int)$row['user_id'],
                (int)$row['server_id'],
                (string)$row['server_type'],
                (string)$row['server_rate'],
                (int)$row['u'],
                (int)$row['d'],
                $recordType,
                $recordAt,
                $now,
                $now,
            ];
        }

        $this->incrementUpsert(
            $table,
            ['user_id', 'server_id', 'server_type', 'server_rate', 'u', 'd', 'record_type', 'record_at', 'created_at', 'updated_at'],
            $rows
        );
    }

    private function incrementUpsert(string $table, array $columns, array $rows): void
    {
        if (!$rows) {
            return;
        }

        $quotedColumns = implode(', ', array_map(static fn ($column) => "`{$column}`", $columns));
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, 500) as $chunk) {
            $bindings = [];
            foreach ($chunk as $row) {
                array_push($bindings, ...$row);
            }
            $values = implode(', ', array_fill(0, count($chunk), $rowPlaceholder));
            DB::statement(
                "INSERT INTO `{$table}` ({$quotedColumns}) VALUES {$values}
                 ON DUPLICATE KEY UPDATE
                    `u` = `u` + VALUES(`u`),
                    `d` = `d` + VALUES(`d`),
                    `updated_at` = VALUES(`updated_at`)",
                $bindings
            );
        }
    }

    public function getStatRecord($type)
    {
        $startAt = $this->startAt ?: strtotime(date('Y-m-d', strtotime('-30 days')));
        $endAt = $this->endAt ?: strtotime('+1 day', strtotime(date('Y-m-d')));

        $query = Stat::where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->orderBy('record_at', 'ASC');

        switch ($type) {
            case "paid_total": {
                return $query->select(['*', DB::raw('paid_total / 100 as paid_total')])
                    ->get();
            }
            case "commission_total": {
                return $query->select(['*', DB::raw('commission_total / 100 as commission_total')])
                    ->get();
            }
            case "register_count": {
                return $query->get();
            }
        }

        return collect();
    }

    public function getRanking($type, $limit = 20)
    {
        if (!$this->startAt) {
            $this->setStartAt(strtotime(date('Y-m-d', strtotime('-30 days'))));
        }
        if (!$this->endAt) {
            $this->setEndAt(strtotime('+1 day', strtotime(date('Y-m-d'))));
        }

        switch ($type) {
            case 'server_traffic_rank':
                return $this->buildServerTrafficRank($limit);
            case 'user_consumption_rank':
                return $this->buildUserConsumptionRank($limit);
            case 'invite_rank':
                return $this->buildInviteRank($limit);
        }
    }

    private function buildInviteRank($limit)
    {
        $stats = User::select(['invite_user_id', DB::raw('count(*) as count')])
            ->where('created_at', '>=', $this->startAt)
            ->where('created_at', '<', $this->endAt)
            ->whereNotNull('invite_user_id')
            ->groupBy('invite_user_id')
            ->orderBy('count', 'DESC')
            ->limit($limit)
            ->get();
        $users = User::whereIn('id', $stats->pluck('invite_user_id')->toArray())->get(['id', 'email'])->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['invite_user_id']])) continue;
            $stats[$k]['email'] = $users[$v['invite_user_id']]['email'];
        }
        return $stats;
    }

    private function buildUserConsumptionRank($limit)
    {
        $stats = StatUser::select([
            'user_id',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_type', 'd')
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
        $users = User::whereIn('id', $stats->pluck('user_id')->toArray())->get(['id', 'email'])->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['user_id']])) continue;
            $stats[$k]['email'] = $users[$v['user_id']]['email'];
        }
        return $stats;
    }

    private function buildServerTrafficRank($limit)
    {
        return StatServer::select([
            'server_id',
            'server_type',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_type', 'd')
            ->where('record_at', '>=', $this->startAt)
            ->where('record_at', '<', $this->endAt)
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
    }
}
