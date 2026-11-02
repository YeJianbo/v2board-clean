<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PublicStatusController extends Controller
{
    private const ONLINE_WINDOW_SECONDS = 180;

    public function index(): Response
    {
        if (!(bool) config('v2board.public_status_enable', 1)) {
            abort(404);
        }

        return response()
            ->view('public-status', [
                'title' => '主机监控',
                'machines' => $this->machineStatus(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function data(): JsonResponse
    {
        if (!(bool) config('v2board.public_status_enable', 1)) {
            abort(404);
        }

        return response()
            ->json(['data' => $this->machineStatus()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function dataForMachineIds(array $machineIds): array
    {
        abort_unless((bool) config('v2board.public_status_enable', 1), 404);
        if ($machineIds === []) {
            return [];
        }

        $allowed = array_fill_keys($machineIds, true);
        return array_values(array_filter(
            $this->machineStatus(),
            fn ($row) => isset($allowed[(int) $row['id']]),
        ));
    }

    private function machineStatus(): array
    {
        try {
            $cached = $this->probeCache()->get(Machine::PUBLIC_STATUS_CACHE_KEY);
            if (is_array($cached)) return $cached;
        } catch (\Throwable $cacheError) {
            // A cache outage must not prevent reading database snapshots.
        }
        try {
            $data = $this->buildMachineStatus();
        } catch (\Throwable $databaseError) {
            abort(503, '监控数据暂不可用，请稍后重试');
        }
        try {
            $this->probeCache()->put(Machine::PUBLIC_STATUS_CACHE_KEY, $data, 5);
        } catch (\Throwable $cacheError) {
            // Keep the successfully built response even when caching fails.
        }
        return $data;
    }

    protected function probeCache()
    {
        return Machine::probeCache();
    }

    private function decodeStatus(Machine $machine, $cached): array
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

    private function buildMachineStatus(): array
    {
        $machines = Machine::query()
            ->select(['id', 'name', 'country_code', 'country_name', 'status', 'sort'])
            ->where('public_visible', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        $cached = [];
        if ($machines->isNotEmpty()) {
            try {
                $cached = $this->probeCache()->many($machines->map(fn ($machine) => $machine->statusCacheKey())->all());
            } catch (\Throwable $e) {
                // Database snapshots keep monitoring available during a cache outage.
            }
        }

        return $machines->map(function (Machine $machine) use ($cached) {
                $status = $this->decodeStatus($machine, $cached[$machine->statusCacheKey()] ?? null);
                $lastSeenAt = (int) ($status['reported_at'] ?? 0);
                $isOnline = $lastSeenAt > 0 && (time() - $lastSeenAt) < self::ONLINE_WINDOW_SECONDS;
                $trafficReportedAt = (int) ($status['traffic_reported_at'] ?? 0);
                $hasFreshTraffic = $isOnline
                    && $trafficReportedAt > 0
                    && (time() - $trafficReportedAt) <= 20;
                $location = $machine->resolveLocation($status);

                return [
                    'id' => (int) $machine->id,
                    'name' => trim((string) $machine->name) ?: 'Machine',
                    'country' => $location['country_name'] ?: '未知地区',
                    'country_code' => $location['country_code'],
                    'is_online' => $isOnline,
                    'cpu' => $this->percentage($status['cpu'] ?? null),
                    'mem' => $this->percentage($status['mem'] ?? null),
                    'system' => $this->systemText($status),
                    'load' => $this->loadText($status),
                    'net_in' => $hasFreshTraffic ? $this->rateText($status['net_in_rate'] ?? null) : '--',
                    'net_out' => $hasFreshTraffic ? $this->rateText($status['net_out_rate'] ?? null) : '--',
                ];
            })
            ->values()
            ->all();
    }

    private function systemText(array $status): string
    {
        $parts = array_values(array_filter([
            trim((string) ($status['os'] ?? '')),
            trim((string) ($status['arch'] ?? '')),
        ]));

        return $parts ? implode(' · ', $parts) : '--';
    }

    private function loadText(array $status): string
    {
        $loads = [];
        foreach (['load1', 'load5', 'load15'] as $key) {
            if (!isset($status[$key]) || !is_numeric($status[$key])) {
                return '--';
            }
            $loads[] = number_format(max(0, (float) $status[$key]), 2, '.', '');
        }

        return implode(' / ', $loads);
    }

    private function rateText($value): string
    {
        if (!is_numeric($value) || (float) $value < 0) {
            return '--';
        }

        $units = ['B/s', 'KB/s', 'MB/s', 'GB/s', 'TB/s'];
        $rate = (float) $value;
        $index = 0;
        while ($rate >= 1024 && $index < count($units) - 1) {
            $rate /= 1024;
            $index++;
        }

        $digits = $index === 0 || $rate >= 10 ? 0 : 1;
        return number_format($rate, $digits, '.', '') . ' ' . $units[$index];
    }

    private function percentage($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round(max(0, min(100, (float) $value)), 1);
    }
}
