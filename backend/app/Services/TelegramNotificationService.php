<?php

namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TelegramNotificationService
{
    private const MACHINE_STATE_PREFIX = 'telegram:machine:monitor:v1:';
    private const MACHINE_QUALITY_STATE_PREFIX = 'telegram:machine:quality:v1:';
    private const USER_TRAFFIC_STATE_PREFIX = 'telegram:user:traffic:v1:';

    private TelegramService $telegramService;
    private $cacheRepository = null;

    public function __construct(?TelegramService $telegramService = null)
    {
        $this->telegramService = $telegramService ?: new TelegramService();
    }

    public function checkMachines(bool $dryRun = false): array
    {
        $summary = [
            'machines' => 0,
            'online' => 0,
            'offline' => 0,
            'alerts' => 0,
            'recoveries' => 0,
            'renewals' => 0,
        ];

        if (!$this->telegramEnabled() || !(bool) $this->setting('telegram_machine_alert_enable', 1)) {
            return $summary;
        }

        $now = time();
        $offlineAfter = $this->clamp((int) $this->setting('telegram_machine_offline_seconds', 300), 180, 86400);
        $statusAlertEnabled = (bool) $this->setting('telegram_machine_status_alert_enable', 1);
        Machine::query()
            ->select([
                'id',
                'name',
                'host',
                'status',
                'maintenance_until',
                'maintenance_note',
                'idc_name',
                'billing_amount',
                'billing_currency',
                'billing_cycle',
                'renew_at',
                'renew_alert_enabled',
                'renew_alert_days',
                'updated_at',
            ])
            ->chunkById(100, function (Collection $machines) use ($now, $offlineAfter, $statusAlertEnabled, $dryRun, &$summary) {
                $this->checkMachineBatch($machines, $now, $offlineAfter, $statusAlertEnabled, $dryRun, $summary);
            });

        return $summary;
    }

    private function checkMachineBatch(
        Collection $machines,
        int $now,
        int $offlineAfter,
        bool $statusAlertEnabled,
        bool $dryRun,
        array &$summary
    ): void {
        $statusKeys = [];
        $stateKeys = [];
        foreach ($machines as $machine) {
            $statusKeys[] = $machine->statusCacheKey();
            $stateKeys[] = self::MACHINE_STATE_PREFIX . $machine->id;
            $stateKeys[] = $machine->renewalAlertStateCacheKey();
        }
        $cachedStatuses = Machine::probeCache()->many($statusKeys);
        $cachedStates = $this->cache()->many($stateKeys);

        foreach ($machines as $machine) {
            if ($this->checkMachineRenewal($machine, $now, $dryRun, $cachedStates[$machine->renewalAlertStateCacheKey()] ?? null)) {
                $summary['alerts']++;
                $summary['renewals']++;
            }

            $status = $this->machineStatus($machine, $cachedStatuses[$machine->statusCacheKey()] ?? null);
            $reportedAt = (int) ($status['reported_at'] ?? 0);
            if ($reportedAt <= 0) {
                continue;
            }

            $summary['machines']++;
            $online = ($now - $reportedAt) < $offlineAfter;
            $summary[$online ? 'online' : 'offline']++;

            $stateKey = self::MACHINE_STATE_PREFIX . $machine->id;
            $state = $cachedStates[$stateKey] ?? null;
            if ($machine->isInMaintenance($now)) {
                $state = $this->initialMachineState($online, $now);
                $state['maintenance_active'] = true;
                $state['offline_since'] = $online ? 0 : $reportedAt + $offlineAfter;
                if (!$dryRun) {
                    $this->cache()->forever($stateKey, $state);
                }
                continue;
            }

            if (!is_array($state) || !array_key_exists('online', $state)) {
                if (!$dryRun) {
                    $this->cache()->forever($stateKey, $this->initialMachineState($online, $now));
                }
                continue;
            }

            if ((bool) ($state['maintenance_active'] ?? false)) {
                $state = $this->initialMachineState($online, $now);
                if (!$online) {
                    $notified = false;
                    if ($statusAlertEnabled && $this->sendMachineOffline($machine, $status, $reportedAt, $offlineAfter, $dryRun)) {
                        $summary['alerts']++;
                        $notified = true;
                    }
                    $state['offline_since'] = $reportedAt + $offlineAfter;
                    $state['offline_notified'] = $notified;
                }
                if (!$dryRun) {
                    $this->cache()->forever($stateKey, $state);
                }
                continue;
            }

            if ($online !== (bool) $state['online']) {
                if ($online) {
                    $shouldNotify = $statusAlertEnabled && (bool) ($state['offline_notified'] ?? false);
                    if ($shouldNotify && $this->sendMachineRecovery($machine, $status, $state, $reportedAt, $dryRun)) {
                        $summary['recoveries']++;
                    }
                    $state['online'] = true;
                    $state['offline_since'] = 0;
                    $state['offline_notified'] = false;
                    $state['resources'] = [];
                } else {
                    $notified = false;
                    if ($statusAlertEnabled && $this->sendMachineOffline($machine, $status, $reportedAt, $offlineAfter, $dryRun)) {
                        $summary['alerts']++;
                        $notified = true;
                    }
                    $state['online'] = false;
                    $state['offline_since'] = $reportedAt + $offlineAfter;
                    $state['offline_notified'] = $notified;
                    $state['resources'] = [];
                }
            }

            if ($online && (bool) $state['online']) {
                [$state, $alerts, $recoveries] = $this->checkMachineResources($machine, $status, $state, $now, $dryRun);
                $summary['alerts'] += $alerts;
                $summary['recoveries'] += $recoveries;
            }

            $state['last_checked_at'] = $now;
            if (!$dryRun) {
                $this->cache()->forever($stateKey, $state);
            }
        }
    }

    public function notifyMachineIpChanges(Machine $machine, array $changes, int $updatedNodes): bool
    {
        if (!$this->telegramEnabled() || !(bool) $this->setting('telegram_machine_alert_enable', 1)) return false;
        $lines = ['主机 IP 变动', '机器：' . $machine->name];
        foreach ($changes as $change) {
            $lines[] = 'IPv' . $change['family'] . '：' . $change['old'] . ' → ' . $change['new'];
        }
        $lines[] = $updatedNodes > 0 ? '已同步 ' . $updatedNodes . ' 个节点入口' : '未修改节点入口';
        return $this->telegramService->sendMessageWithAdmin(implode("\n", $lines), false, '') > 0;
    }

    public function remindUserTraffic(User $user): bool
    {
        if (
            !$this->telegramEnabled()
            || !(bool) $this->setting('telegram_user_traffic_alert_enable', 1)
            || !$user->remind_traffic
            || !$user->telegram_id
        ) {
            return false;
        }

        if ($user->expired_at !== null && (int) $user->expired_at < time()) {
            return false;
        }

        $total = (int) $user->transfer_enable;
        $used = max(0, (int) $user->u + (int) $user->d);
        $stateKey = self::USER_TRAFFIC_STATE_PREFIX . $user->id;
        if ($total <= 0) {
            $this->cache()->forget($stateKey);
            return false;
        }

        $percentage = ($used / $total) * 100;
        $threshold = $this->clamp((int) $this->setting('telegram_user_traffic_threshold', 95), 50, 100);
        $level = $percentage >= 100 ? 'exhausted' : ($percentage >= $threshold ? 'warning' : 'normal');
        $previousLevel = (string) $this->cache()->get($stateKey, 'normal');

        if ($level === 'normal') {
            if ($previousLevel !== 'normal') {
                $this->cache()->forever($stateKey, 'normal');
            }
            return false;
        }

        if ($level === $previousLevel) {
            return false;
        }

        $remaining = max(0, $total - $used);
        $title = $level === 'exhausted' ? '流量已耗尽' : '流量使用提醒';
        $message = implode("\n", [
            ($level === 'exhausted' ? '🔴 ' : '🟠 ') . $title,
            '已使用：' . $this->formatBytes($used) . ' / ' . $this->formatBytes($total),
            '使用比例：' . number_format(min($percentage, 999.99), 2) . '%',
            '剩余流量：' . $this->formatBytes($remaining),
            $level === 'exhausted' ? '请及时续费或购买流量包。' : '请留意剩余流量，避免服务中断。',
        ]);

        SendTelegramJob::dispatch((int) $user->telegram_id, $message, '');
        $this->cache()->forever($stateKey, $level);
        return true;
    }

    public function recordMachineTraffic(
        Machine $machine,
        int $netInBytes,
        int $netOutBytes,
        ?int $recordedAt = null
    ): bool {
        $netInBytes = max(0, $netInBytes);
        $netOutBytes = max(0, $netOutBytes);
        if (($netInBytes + $netOutBytes) <= 0) {
            return false;
        }

        $now = $recordedAt ?: time();
        $stateKey = $machine->trafficAlertStateCacheKey();
        $state = $this->getMachineTrafficState($machine, $now);

        $state['net_in'] = max(0, (int) ($state['net_in'] ?? 0)) + $netInBytes;
        $state['net_out'] = max(0, (int) ($state['net_out'] ?? 0)) + $netOutBytes;
        $state['updated_at'] = $now;
        $total = $state['net_in'] + $state['net_out'];
        $state['total'] = $total;
        $threshold = max(0, (int) $machine->traffic_alert_bytes);
        $alertEnabled = (bool) $machine->traffic_alert_enabled && $threshold > 0;
        $alertSignature = ($alertEnabled ? '1' : '0') . ':' . $threshold;
        if ((string) ($state['alert_signature'] ?? '') !== $alertSignature) {
            $state['alert_signature'] = $alertSignature;
            $state['alerted'] = false;
            unset($state['alerted_at']);
        }
        $sent = false;

        if (
            $alertEnabled
            &&
            $total >= $threshold
            && !(bool) ($state['alerted'] ?? false)
            && !$machine->isInMaintenance($now)
            && $this->telegramEnabled()
            && (bool) $this->setting('telegram_machine_alert_enable', 1)
        ) {
            $sent = $this->sendMachineTrafficMessage($machine, $state, $threshold);
            if ($sent) {
                $state['alerted'] = true;
                $state['alerted_at'] = $now;
            }
        }

        $ttl = max(3600, ((int) $state['ends_at'] - $now) + 86400);
        $this->cache()->put($stateKey, $state, $ttl);

        return $sent;
    }

    public function getMachineTrafficState(Machine $machine, ?int $at = null): array
    {
        $cached = $this->cache()->get($machine->trafficAlertStateCacheKey());
        return $this->buildMachineTrafficState($machine, $cached, $at ?: time());
    }

    public function getMachineTrafficStates(iterable $machines, ?int $at = null): array
    {
        $list = []; $keys = [];
        foreach ($machines as $machine) {
            $list[(int) $machine->id] = $machine;
            $keys[(int) $machine->id] = $machine->trafficAlertStateCacheKey();
        }
        if (!$list) return [];
        $cached = $this->cache()->many(array_values($keys));
        $now = $at ?: time();
        $states = [];
        foreach ($list as $id => $machine) {
            $states[$id] = $this->buildMachineTrafficState($machine, $cached[$keys[$id]] ?? null, $now);
        }
        return $states;
    }

    private function buildMachineTrafficState(Machine $machine, $cached, int $now): array
    {
        $cached = is_array($cached) ? $cached : [];
        $period = $machine->resolveTrafficPeriod($now, $cached);
        $samePeriod = (string) ($cached['cycle_signature'] ?? '') === (string) $period['signature']
            && (int) ($cached['started_at'] ?? 0) === (int) $period['started_at']
            && (int) ($cached['ends_at'] ?? 0) === (int) $period['ends_at'];

        $state = $samePeriod ? $cached : [
            'cycle_signature' => (string) $period['signature'],
            'mode' => (string) $period['mode'],
            'started_at' => (int) $period['started_at'],
            'ends_at' => (int) $period['ends_at'],
            'net_in' => 0,
            'net_out' => 0,
            'alerted' => false,
        ];
        $netIn = max(0, (int) ($state['net_in'] ?? 0));
        $netOut = max(0, (int) ($state['net_out'] ?? 0));
        $threshold = max(0, (int) $machine->traffic_alert_bytes);
        $total = $netIn + $netOut;

        return array_merge($state, [
            'mode' => (string) $period['mode'],
            'net_in' => $netIn,
            'net_out' => $netOut,
            'total' => $total,
            'limit_bytes' => $threshold,
            'remaining_bytes' => $threshold > 0 ? max(0, $threshold - $total) : 0,
            'percentage' => $threshold > 0 ? round(($total / $threshold) * 100, 2) : 0,
        ]);
    }

    public function handleNetworkQualitySamples(Machine $machine, array $samples): array
    {
        $summary = ['alerts' => 0, 'recoveries' => 0];
        if (
            !$this->telegramEnabled()
            || !(bool) $this->setting('telegram_machine_alert_enable', 1)
            || !(bool) $this->setting('telegram_machine_quality_alert_enable', 0)
        ) {
            return $summary;
        }

        if ($machine->isInMaintenance()) {
            foreach ($samples as $sample) {
                $targetKey = trim((string) ($sample['target_key'] ?? ''));
                if ($targetKey !== '') {
                    $this->cache()->forget(self::MACHINE_QUALITY_STATE_PREFIX . $machine->id . ':' . $targetKey);
                }
            }
            return $summary;
        }

        $latencyThreshold = max(1, min(60000, (float) $this->setting('telegram_machine_quality_latency_threshold', 300)));
        $lossThreshold = max(1, min(100, (float) $this->setting('telegram_machine_quality_loss_threshold', 30)));
        $consecutiveRequired = $this->clamp((int) $this->setting('telegram_machine_quality_consecutive_count', 3), 1, 30);
        $cooldown = $this->clamp((int) $this->setting('telegram_machine_quality_cooldown_seconds', 1800), 60, 86400);
        $now = time();

        $stateKeys = [];
        foreach ($samples as $sample) {
            $targetKey = (string) ($sample['target_key'] ?? '');
            if ($targetKey !== '') {
                $stateKey = self::MACHINE_QUALITY_STATE_PREFIX . $machine->id . ':' . $targetKey;
                $stateKeys[$stateKey] = $stateKey;
            }
        }
        if (!$stateKeys) {
            return $summary;
        }
        $cachedStates = $this->cache()->many(array_values($stateKeys));

        foreach ($samples as $sample) {
            $targetKey = (string) ($sample['target_key'] ?? '');
            if ($targetKey === '') {
                continue;
            }

            $signature = implode(':', [
                (string) ($sample['probe_type'] ?? 'icmp'),
                (int) ($sample['target_port'] ?? 80),
                (string) ($sample['ip_version'] ?? 'auto'),
                (string) ($sample['target_host'] ?? ''),
            ]);
            $stateKey = self::MACHINE_QUALITY_STATE_PREFIX . $machine->id . ':' . $targetKey;
            $state = $cachedStates[$stateKey] ?? null;
            if (!is_array($state) || ($state['signature'] ?? '') !== $signature) {
                $state = [
                    'signature' => $signature,
                    'consecutive' => 0,
                    'alerted' => false,
                    'last_alert_at' => 0,
                ];
            }

            $latency = isset($sample['latency_avg']) && is_numeric($sample['latency_avg'])
                ? (float) $sample['latency_avg']
                : null;
            $loss = max(0, min(100, (float) ($sample['packet_loss'] ?? 100)));
            $abnormal = $loss >= $lossThreshold || ($latency !== null && $latency >= $latencyThreshold);

            if ($abnormal) {
                $state['consecutive'] = (int) ($state['consecutive'] ?? 0) + 1;
                if (
                    !(bool) ($state['alerted'] ?? false)
                    && $state['consecutive'] >= $consecutiveRequired
                    && ($now - (int) ($state['last_alert_at'] ?? 0)) >= $cooldown
                    && $this->sendNetworkQualityMessage(
                        $machine,
                        $sample,
                        false,
                        $latencyThreshold,
                        $lossThreshold,
                        $state['consecutive']
                    )
                ) {
                    $state['alerted'] = true;
                    $state['last_alert_at'] = $now;
                    $summary['alerts']++;
                }
            } else {
                $state['consecutive'] = 0;
                if (
                    (bool) ($state['alerted'] ?? false)
                    && $this->sendNetworkQualityMessage($machine, $sample, true, $latencyThreshold, $lossThreshold, 0)
                ) {
                    $state['alerted'] = false;
                    $summary['recoveries']++;
                }
            }

            $state['last_sample_at'] = $now;
            $this->cache()->forever($stateKey, $state);
            // Repeated targets in one report must see the preceding sample's state.
            $cachedStates[$stateKey] = $state;
        }

        return $summary;
    }

    private function checkMachineResources(Machine $machine, array $status, array $state, int $now, bool $dryRun): array
    {
        if (!(bool) $this->setting('telegram_machine_resource_alert_enable', 1)) {
            $state['resources'] = [];
            return [$state, 0, 0];
        }

        $duration = $this->clamp((int) $this->setting('telegram_machine_resource_duration_seconds', 180), 60, 86400);
        $metrics = [];
        if ((bool) $this->setting('telegram_machine_cpu_alert_enable', 1)) {
            $metrics['cpu'] = [
                'label' => 'CPU',
                'value' => $this->numericStatusValue($status, 'cpu'),
                'threshold' => $this->clamp((int) $this->setting('telegram_machine_cpu_threshold', 90), 1, 100),
                'suffix' => '%',
                'duration' => $duration,
            ];
        }
        if ((bool) $this->setting('telegram_machine_memory_alert_enable', 1)) {
            $metrics['mem'] = [
                'label' => '内存',
                'value' => $this->numericStatusValue($status, 'mem'),
                'threshold' => $this->clamp((int) $this->setting('telegram_machine_memory_threshold', 90), 1, 100),
                'suffix' => '%',
                'duration' => $duration,
            ];
        }
        if ((bool) $this->setting('telegram_machine_disk_alert_enable', 1)) {
            $metrics['disk'] = [
                'label' => '磁盘',
                'value' => $this->numericStatusValue($status, 'disk'),
                'threshold' => $this->clamp((int) $this->setting('telegram_machine_disk_threshold', 95), 1, 100),
                'suffix' => '%',
                'duration' => $duration,
            ];
        }

        $networkThreshold = max(0, (float) $this->setting('telegram_machine_network_mbps_threshold', 0));
        if ((bool) $this->setting('telegram_machine_network_alert_enable', 0) && $networkThreshold > 0) {
            $netIn = $this->numericStatusValue($status, 'net_in_rate');
            $netOut = $this->numericStatusValue($status, 'net_out_rate');
            $networkMbps = $netIn !== null && $netOut !== null
                ? max(0, $netIn, $netOut) / 1000000 * 8
                : null;
            $metrics['network'] = [
                'label' => '瞬时流量',
                'value' => $networkMbps !== null ? round($networkMbps, 2) : null,
                'threshold' => $networkThreshold,
                'suffix' => ' Mbps',
                'duration' => $duration,
            ];
        }

        $resourceState = is_array($state['resources'] ?? null) ? $state['resources'] : [];
        foreach (['cpu', 'mem', 'disk', 'network'] as $key) {
            if (!isset($metrics[$key])) {
                unset($resourceState[$key]);
            }
        }
        $newAlerts = [];
        $newRecoveries = [];

        foreach ($metrics as $key => $metric) {
            if ($metric['value'] === null) {
                continue;
            }

            $current = is_array($resourceState[$key] ?? null)
                ? $resourceState[$key]
                : ['high_since' => 0, 'alerted' => false];
            $isHigh = $metric['value'] >= $metric['threshold'];
            $isRecovered = $metric['value'] < max(0, $metric['threshold'] - 5);

            if ($isHigh) {
                if ((int) ($current['high_since'] ?? 0) <= 0) {
                    $current['high_since'] = $now;
                }
                if (
                    !(bool) ($current['alerted'] ?? false)
                    && ($now - (int) $current['high_since']) >= $duration
                ) {
                    $newAlerts[$key] = $metric;
                }
            } elseif ($isRecovered) {
                $current['high_since'] = 0;
                if ((bool) ($current['alerted'] ?? false)) {
                    $newRecoveries[$key] = $metric;
                }
            }

            $resourceState[$key] = $current;
        }

        $alertCount = 0;
        $recoveryCount = 0;
        if ($newAlerts && $this->sendResourceMessage($machine, $status, $newAlerts, false, $dryRun)) {
            $alertCount++;
            foreach (array_keys($newAlerts) as $key) {
                $resourceState[$key]['alerted'] = true;
            }
        }
        if ($newRecoveries && $this->sendResourceMessage($machine, $status, $newRecoveries, true, $dryRun)) {
            $recoveryCount++;
            foreach (array_keys($newRecoveries) as $key) {
                $resourceState[$key]['alerted'] = false;
            }
        }

        $state['resources'] = $resourceState;
        return [$state, $alertCount, $recoveryCount];
    }

    private function checkMachineRenewal(Machine $machine, int $now, bool $dryRun, $cachedState): bool
    {
        $stateKey = $machine->renewalAlertStateCacheKey();
        $renewAt = max(0, (int) $machine->renew_at);
        if (!(bool) $machine->renew_alert_enabled || $renewAt <= 0 || $machine->isInMaintenance($now)) {
            if (!$dryRun) {
                $this->cache()->forget($stateKey);
            }
            return false;
        }

        $alertDays = max(0, min(365, (int) ($machine->renew_alert_days ?? 7)));
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone)->setTime(0, 0);
        $renewDate = (new \DateTimeImmutable('@' . $renewAt))->setTimezone($timezone)->setTime(0, 0);
        $remainingDays = (int) $today->diff($renewDate)->format('%r%a');
        if ($remainingDays > $alertDays) {
            if (!$dryRun) {
                $this->cache()->forget($stateKey);
            }
            return false;
        }

        $signature = $renewAt . ':' . $alertDays;
        if ((string) $cachedState === $signature) {
            return false;
        }

        if (!$this->sendMachineRenewalMessage($machine, $remainingDays, $dryRun)) {
            return false;
        }

        if (!$dryRun) {
            $this->cache()->forever($stateKey, $signature);
        }
        return true;
    }

    private function sendMachineRenewalMessage(Machine $machine, int $remainingDays, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $deadline = $remainingDays < 0
            ? '已逾期 ' . abs($remainingDays) . ' 天'
            : ($remainingDays === 0 ? '今天到期' : $remainingDays . ' 天后到期');
        $amount = (float) $machine->billing_amount;
        $currency = strtoupper(trim((string) ($machine->billing_currency ?: 'USD')));
        $cycleLabels = [
            'monthly' => '月付',
            'quarterly' => '季付',
            'semiannual' => '半年付',
            'yearly' => '年付',
            'custom' => '自定义',
        ];
        $cycle = $cycleLabels[(string) $machine->billing_cycle] ?? '自定义';
        $lines = [
            ($remainingDays < 0 ? '🔴 ' : '🟠 ') . '主机续费提醒',
            '名称：' . $machine->name,
        ];
        if (trim((string) $machine->idc_name) !== '') {
            $lines[] = 'IDC：' . trim((string) $machine->idc_name);
        }
        if ($amount > 0) {
            $lines[] = '费用：' . rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . ' ' . $currency . ' / ' . $cycle;
        }
        $lines[] = '续费日期：' . date('Y-m-d', (int) $machine->renew_at);
        $lines[] = '状态：' . $deadline;

        return $this->telegramService->sendMessageWithAdmin(implode("\n", $lines), false, '') > 0;
    }

    private function sendMachineOffline(Machine $machine, array $status, int $reportedAt, int $offlineAfter, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $message = implode("\n", [
            '🔴 主机离线',
            '名称：' . $machine->name,
            '地址：' . $this->machineAddress($machine, $status),
            '最后上报：' . date('Y-m-d H:i:s', $reportedAt),
            '离线时长：' . $this->formatDuration(max(0, time() - $reportedAt)),
            '判定阈值：' . $this->formatDuration($offlineAfter),
        ]);

        return $this->telegramService->sendMessageWithAdmin($message, false, '') > 0;
    }

    private function sendMachineRecovery(Machine $machine, array $status, array $state, int $reportedAt, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $offlineSince = (int) ($state['offline_since'] ?? 0);
        $message = implode("\n", [
            '🟢 主机恢复在线',
            '名称：' . $machine->name,
            '地址：' . $this->machineAddress($machine, $status),
            '恢复时间：' . date('Y-m-d H:i:s', $reportedAt),
            '中断时长：' . $this->formatDuration($offlineSince > 0 ? max(0, $reportedAt - $offlineSince) : 0),
        ]);

        return $this->telegramService->sendMessageWithAdmin($message, false, '') > 0;
    }

    private function sendResourceMessage(Machine $machine, array $status, array $metrics, bool $recovered, bool $dryRun): bool
    {
        if ($dryRun) {
            return true;
        }

        $lines = [
            ($recovered ? '🟢 主机资源恢复' : '🟠 主机资源告警'),
            '名称：' . $machine->name,
            '地址：' . $this->machineAddress($machine, $status),
        ];
        foreach ($metrics as $metric) {
            $lines[] = sprintf(
                '%s：%s%s（阈值 %s%s）',
                $metric['label'],
                number_format((float) $metric['value'], 2),
                $metric['suffix'],
                number_format((float) $metric['threshold'], 2),
                $metric['suffix']
            );
        }
        if (!$recovered) {
            $firstMetric = reset($metrics);
            if (is_array($firstMetric) && !empty($firstMetric['duration'])) {
                $lines[] = '持续时间：' . $this->formatDuration((int) $firstMetric['duration']);
            }
        }

        return $this->telegramService->sendMessageWithAdmin(implode("\n", $lines), false, '') > 0;
    }

    private function sendMachineTrafficMessage(Machine $machine, array $state, int $threshold): bool
    {
        $netIn = max(0, (int) ($state['net_in'] ?? 0));
        $netOut = max(0, (int) ($state['net_out'] ?? 0));
        $startedAt = (int) ($state['started_at'] ?? time());
        $endsAt = (int) ($state['ends_at'] ?? $startedAt);
        $modeLabels = [
            'rolling' => '滚动周期',
            'daily' => '每日重置',
            'monthly' => '每月重置',
        ];
        $message = implode("\n", [
            '🟠 主机计费流量告警',
            '名称：' . $machine->name,
            '重置方式：' . ($modeLabels[(string) ($state['mode'] ?? '')] ?? '滚动周期'),
            '统计周期：' . date('Y-m-d H:i:s', $startedAt) . ' - ' . date('Y-m-d H:i:s', $endsAt),
            '接收流量：' . $this->formatBytes($netIn),
            '发送流量：' . $this->formatBytes($netOut),
            '计费流量：' . $this->formatBytes($netIn + $netOut),
            '告警阈值：' . $this->formatBytes($threshold),
        ]);

        return $this->telegramService->sendMessageWithAdmin($message, false, '') > 0;
    }

    private function sendNetworkQualityMessage(
        Machine $machine,
        array $sample,
        bool $recovered,
        float $latencyThreshold,
        float $lossThreshold,
        int $consecutive
    ): bool {
        $latency = isset($sample['latency_avg']) && is_numeric($sample['latency_avg'])
            ? number_format((float) $sample['latency_avg'], 2) . ' ms'
            : '无响应';
        $loss = number_format((float) ($sample['packet_loss'] ?? 100), 2) . '%';
        $probeType = strtolower((string) ($sample['probe_type'] ?? 'icmp'));
        $ipVersion = (string) ($sample['ip_version'] ?? 'auto');
        $probe = strtoupper($probeType);
        if ($probeType === 'tcp') {
            $probe .= ':' . (int) ($sample['target_port'] ?? 80);
        }
        $probe .= ' / ' . ($ipVersion === '4' ? 'IPv4' : ($ipVersion === '6' ? 'IPv6' : '自动'));

        $lines = [
            $recovered ? '🟢 网络质量恢复' : '🟠 网络质量告警',
            '主机：' . $machine->name,
            '目标：' . (string) ($sample['target_name'] ?? $sample['target_key'] ?? '-'),
            '探测：' . $probe,
            '延迟：' . $latency . '（阈值 ' . number_format($latencyThreshold, 0) . ' ms）',
            '丢包：' . $loss . '（阈值 ' . number_format($lossThreshold, 0) . '%）',
            '时间：' . date('Y-m-d H:i:s'),
        ];
        if (!$recovered) {
            $lines[] = '连续异常：' . $consecutive . ' 次';
        }

        return $this->telegramService->sendMessageWithAdmin(implode("\n", $lines), false, '') > 0;
    }

    private function initialMachineState(bool $online, int $now): array
    {
        return [
            'online' => $online,
            'offline_since' => 0,
            'offline_notified' => false,
            'resources' => [],
            'initialized_at' => $now,
            'last_checked_at' => $now,
        ];
    }

    private function machineStatus(Machine $machine, $cached): array
    {
        if (is_array($cached)) {
            return $cached;
        }
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        $decoded = json_decode((string) $machine->status, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }

    private function machineAddress(Machine $machine, array $status): string
    {
        foreach (['ddns_host', 'primary_ip', 'public_ipv4', 'public_ipv6', 'remote_ip', 'ip'] as $key) {
            $value = trim((string) ($status[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) $machine->host) ?: '-';
    }

    private function numericStatusValue(array $status, string $key): ?float
    {
        if (!array_key_exists($key, $status) || !is_numeric($status[$key])) {
            return null;
        }
        $value = (float) $status[$key];
        return is_finite($value) ? $value : null;
    }

    private function telegramEnabled(): bool
    {
        return (bool) $this->setting('telegram_bot_enable', 0)
            && trim((string) $this->setting('telegram_bot_token', '')) !== '';
    }

    private function setting(string $key, $default = null)
    {
        return function_exists('admin_setting')
            ? admin_setting($key, config('v2board.' . $key, $default))
            : config('v2board.' . $key, $default);
    }

    private function cache()
    {
        if ($this->cacheRepository !== null) {
            return $this->cacheRepository;
        }

        try {
            $cache = Cache::store('redis');
            $cache->get('telegram:cache:connection-check');
            $this->cacheRepository = $cache;
        } catch (\Throwable $e) {
            $this->cacheRepository = Cache::store('file');
        }

        return $this->cacheRepository;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . '秒';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . '分钟';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . '小时' . floor(($seconds % 3600) / 60) . '分钟';
        }

        return floor($seconds / 86400) . '天' . floor(($seconds % 86400) / 3600) . '小时';
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }
}
