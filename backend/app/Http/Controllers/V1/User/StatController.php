<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use App\Models\StatUserServer;
use App\Models\StatUserServerHour;
use App\Models\StatUserServerMinute;
use Illuminate\Http\Request;

class StatController extends Controller
{
    private const MAX_NODE_TRAFFIC_ROWS = 20000;
    private const MAX_NODE_TRAFFIC_POINTS = 50000;

    public function getTrafficLog(Request $request)
    {
        $monthStart = strtotime(date('Y-m-01'));
        $builder = StatUser::select([
            'u',
            'd',
            'record_at',
            'user_id',
            'server_rate'
        ])
            ->where('user_id', $request->user['id'])
            ->where('record_type', 'd')
            ->where('record_at', '>=', $monthStart)
            ->where('record_at', '<', strtotime('+1 month', $monthStart))
            ->orderBy('record_at', 'DESC');
        return response([
            'data' => $builder->get()
        ]);
    }

    public function getNodeTrafficLog(Request $request)
    {
        $request->validate([
            'period' => 'nullable|in:minute,hour,day',
            'granularity' => 'nullable|in:minute,hour,day',
            'start_at' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_at' => 'nullable|integer|min:1000000000|max:9999999999',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'server_id' => 'nullable|integer|min:1',
            'server_type' => 'nullable|string|max:32',
            'node_keys' => 'nullable|array|max:500',
            'node_keys.*' => 'nullable|string|max:64',
            'include_total' => 'nullable|boolean',
            'include_series' => 'nullable|boolean',
            'include_summary' => 'nullable|boolean',
        ]);

        $period = (string) ($request->input('period') ?: $request->input('granularity') ?: 'day');
        $includeTotal = $request->boolean('include_total', true);
        $includeSeries = $request->boolean('include_series', true);
        $includeSummary = $request->boolean('include_summary', true);

        [$model, $step, $format, $startAt, $endAt, $note] = $this->resolveTrafficPeriod($period, $request);

        $baseQuery = $model
            ->where('user_id', $request->user['id'])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<=', $endAt);

        if ($request->input('server_id')) {
            $baseQuery->where('server_id', (int) $request->input('server_id'));
        }
        if ($request->input('server_type')) {
            $baseQuery->where('server_type', strtolower((string) $request->input('server_type')));
        }

        $requestedNodePairs = $this->parseNodeKeys($request->input('node_keys', []));
        if ($requestedNodePairs->isNotEmpty()) {
            $baseQuery->where(function ($query) use ($requestedNodePairs) {
                foreach ($requestedNodePairs->groupBy('type') as $type => $pairs) {
                    $query->orWhere(function ($subQuery) use ($type, $pairs) {
                        $subQuery->where('server_type', $type)
                            ->whereIn('server_id', $pairs->pluck('id')->all());
                    });
                }
            });
        }

        $logs = (clone $baseQuery)
            ->selectRaw('server_id, server_type as node_type, record_at, MAX(server_rate) as rate, SUM(u) as u, SUM(d) as d, SUM((u + d) * server_rate) as cost')
            ->groupBy('server_id', 'server_type', 'record_at')
            ->orderBy('record_at', 'DESC')
            ->limit(self::MAX_NODE_TRAFFIC_ROWS + 1)
            ->get();
        if ($logs->count() > self::MAX_NODE_TRAFFIC_ROWS) {
            abort(422, '节点流量明细过多，请缩短时间范围或选择更少节点后重试。');
        }

        $summaryRows = collect();
        if ($includeSummary || $includeSeries) {
            // Use the same snapshot as the detail rows while reports continue arriving.
            $totals = [];
            foreach ($logs as $row) {
                $key = $this->buildNodeKey($row->node_type, $row->server_id);
                if (!isset($totals[$key])) {
                    $totals[$key] = (object) [
                        'id' => (int) $row->server_id,
                        'server_type' => (string) $row->node_type,
                        'rate' => (float) $row->rate,
                        'u' => 0,
                        'd' => 0,
                        'total' => 0,
                        'cost' => 0.0,
                    ];
                }
                $total = $totals[$key];
                $total->rate = max($total->rate, (float) $row->rate);
                $total->u += (int) $row->u;
                $total->d += (int) $row->d;
                $total->total = $total->u + $total->d;
                $total->cost += (float) $row->cost;
            }
            $summaryRows = collect($totals)->sortByDesc('total')->values();
        }
        // Sparse logs can expand into a large response when every node gets a full zero-filled timeline.
        if ($includeSeries) {
            $buckets = intdiv($endAt - $startAt, $step) + 1;
            $seriesCount = $summaryRows->count() + ($includeTotal ? 1 : 0);
            if ($buckets * $seriesCount > self::MAX_NODE_TRAFFIC_POINTS) {
                abort(422, '节点流量曲线点数过多，请缩短时间范围、降低精度或选择更少节点后重试。');
            }
        }

        // Eloquent merge keys by model id, but node identity spans protocol tables.
        $nodeRows = [];
        foreach ([$summaryRows, $logs] as $rows) {
            foreach ($rows as $row) {
                $type = strtolower((string) ($row->server_type ?? $row->node_type ?? ''));
                $id = (int) ($row->id ?? $row->server_id ?? 0);
                $key = $this->buildNodeKey($type, $id);
                if (!isset($nodeRows[$key])) {
                    $nodeRows[$key] = (object) ['server_type' => $type, 'server_id' => $id];
                }
            }
        }
        [$names, $protocols] = $this->resolveNodeMetadata($nodeRows);

        $logs->transform(function ($item) use ($names, $protocols) {
            $key = $this->buildNodeKey($item->node_type ?? null, $item->server_id);
            $protocol = (string) ($protocols[$key] ?? $item->node_type);
            $item->type = $protocol;
            $item->protocol = $protocol;
            $item->name = (string) ($names[$key] ?? '未命名节点');
            $item->total = (int) $item->u + (int) $item->d;
            $item->cost = (float) $item->cost;
            return $item;
        });

        $summary = $summaryRows->map(function ($row) use ($names, $protocols) {
            $key = $this->buildNodeKey($row->server_type ?? null, $row->id);
            return [
                'key' => $key,
                'id' => (int) $row->id,
                'server_id' => (int) $row->id,
                'server_type' => (string) $row->server_type,
                'type' => (string) ($protocols[$key] ?? $row->server_type),
                'protocol' => (string) ($protocols[$key] ?? $row->server_type),
                'name' => (string) ($names[$key] ?? '未命名节点'),
                'rate' => (float) $row->rate,
                'u' => (int) $row->u,
                'd' => (int) $row->d,
                'total' => (int) $row->total,
                'cost' => (float) $row->cost,
            ];
        })->values();

        $series = $includeSeries ? $this->buildTrafficSeries(
            $logs,
            $summary,
            $requestedNodePairs,
            $includeTotal,
            $startAt,
            $endAt,
            $step,
            $format
        ) : ['labels' => [], 'series' => []];

        return response([
            'data' => $logs,
            'summary' => $includeSummary ? $summary : [],
            'series' => $series,
            'nodes' => $includeSummary ? $summary : [],
            'meta' => [
                'period' => $period,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'note' => $logs->isEmpty() ? ($note ?: '暂无节点维度流量数据。') : $note,
            ],
        ]);
    }

    private function resolveTrafficPeriod(string $period, Request $request): array
    {
        $endInput = (int) ($request->input('end_at') ?: $request->input('end_time') ?: time());
        $startInput = (int) ($request->input('start_at') ?: $request->input('start_time') ?: 0);

        if ($period === 'minute') {
            $endAt = strtotime(date('Y-m-d H:i:00', $endInput));
            $startAt = $startInput ? strtotime(date('Y-m-d H:i:00', $startInput)) : $endAt - 59 * 60;
            $maxRange = 24 * 3600;
            $note = '分钟曲线从新版部署后开始统计。';
            $result = [StatUserServerMinute::query(), 60, 'H:i', $startAt, $endAt, $note];
        } elseif ($period === 'hour') {
            $endAt = strtotime(date('Y-m-d H:00:00', $endInput));
            $startAt = $startInput ? strtotime(date('Y-m-d H:00:00', $startInput)) : $endAt - 47 * 3600;
            $maxRange = 31 * 24 * 3600;
            $note = '小时曲线从新版部署后开始统计。';
            $result = [StatUserServerHour::query(), 3600, 'm-d H:00', $startAt, $endAt, $note];
        } else {
            $endAt = strtotime(date('Y-m-d', $endInput));
            $startAt = $startInput ? strtotime(date('Y-m-d', $startInput)) : $endAt - 29 * 86400;
            $maxRange = 366 * 86400;
            $note = null;
            $result = [StatUserServer::query(), 86400, 'm-d', $startAt, $endAt, $note];
        }

        if ($startAt > $endAt) {
            [$startAt, $endAt] = [$endAt, $startAt];
        }
        if (($endAt - $startAt) > $maxRange) {
            $startAt = $endAt - $maxRange;
        }

        $result[3] = $startAt;
        $result[4] = $endAt;
        return $result;
    }

    private function parseNodeKeys($nodeKeys)
    {
        return collect($nodeKeys)
            ->map(function ($value) {
                [$type, $id] = array_pad(explode(':', strtolower(trim((string) $value)), 2), 2, null);
                $id = (int) $id;
                if (!$type || $id <= 0) {
                    return null;
                }
                return [
                    'key' => $type . ':' . $id,
                    'type' => $type,
                    'id' => $id,
                ];
            })
            ->filter()
            ->unique('key')
            ->values();
    }

    private function buildTrafficSeries($logs, $summary, $requestedNodePairs, bool $includeTotal, int $startAt, int $endAt, int $step, string $format): array
    {
        $labels = [];
        for ($cursor = $startAt; $cursor <= $endAt; $cursor += $step) {
            $labels[] = [
                'timestamp' => $cursor,
                'label' => date($format, $cursor),
            ];
        }

        $availableKeys = $summary->pluck('key')->values();
        $keysToRender = $requestedNodePairs->isNotEmpty()
            ? $requestedNodePairs->pluck('key')->filter(function ($key) use ($availableKeys) {
                return $availableKeys->contains($key);
            })->values()
            : $availableKeys;

        $summaryMap = $summary->keyBy('key');
        $emptyPoints = [];
        foreach ($labels as $labelMeta) {
            $emptyPoints[$labelMeta['timestamp']] = [
                'timestamp' => $labelMeta['timestamp'],
                'label' => $labelMeta['label'],
                'u' => 0,
                'd' => 0,
                'total' => 0,
                'cost' => 0,
            ];
        }
        $seriesMap = [];
        foreach ($keysToRender as $key) {
            $node = $summaryMap->get($key);
            if (!$node) {
                continue;
            }

            $seriesMap[$key] = [
                'key' => $key,
                'id' => $node['id'],
                'type' => $node['type'],
                'name' => $node['name'],
                'total' => 0,
                'points' => $emptyPoints,
            ];
        }

        $totalPointMap = $includeTotal ? $emptyPoints : [];
        foreach ($logs as $row) {
            $key = $this->buildNodeKey($row->node_type ?? null, $row->server_id);
            $recordAt = (int) $row->record_at;
            if (!isset($seriesMap[$key]['points'][$recordAt])) {
                continue;
            }

            $u = (int) $row->u;
            $d = (int) $row->d;
            $seriesMap[$key]['points'][$recordAt] = [
                'timestamp' => $recordAt,
                'label' => $seriesMap[$key]['points'][$recordAt]['label'],
                'u' => $u,
                'd' => $d,
                'total' => $u + $d,
                'cost' => (float) $row->cost,
            ];
            if ($includeTotal) {
                $totalPointMap[$recordAt]['u'] += $u;
                $totalPointMap[$recordAt]['d'] += $d;
                $totalPointMap[$recordAt]['total'] += $u + $d;
                $totalPointMap[$recordAt]['cost'] += (float) $row->cost;
            }
        }

        $series = collect($seriesMap)->map(function ($item) {
            $points = collect(array_values($item['points']));
            $item['points'] = $points;
            $item['total'] = (int) $points->sum('total');
            return $item;
        })->values();

        if ($includeTotal) {
            $totalPoints = collect(array_values($totalPointMap));

            $series->prepend([
                'key' => 'total',
                'id' => 0,
                'type' => 'all',
                'name' => '全部节点',
                'total' => (int) $totalPoints->sum('total'),
                'points' => $totalPoints,
            ]);
        }

        return [
            'labels' => array_map(function ($item) {
                return $item['label'];
            }, $labels),
            'series' => $series->values(),
        ];
    }

    private function buildNodeKey(?string $type, $id): string
    {
        return strtolower((string) $type) . ':' . (string) $id;
    }

    private function resolveNodeMetadata($rows): array
    {
        $modelMap = [
            'shadowsocks' => \App\Models\ServerShadowsocks::class,
            'trojan' => \App\Models\ServerTrojan::class,
            'vmess' => \App\Models\ServerVmess::class,
            'v2ray' => \App\Models\ServerVmess::class,
            'vless' => \App\Models\ServerVless::class,
            'tuic' => \App\Models\ServerTuic::class,
            'hysteria' => \App\Models\ServerHysteria::class,
            'anytls' => \App\Models\ServerAnytls::class,
            'v2node' => \App\Models\ServerV2node::class,
        ];

        $nameMap = collect();
        $protocolMap = collect();
        $groups = [];
        foreach ($rows as $row) {
            $type = strtolower((string) ($row->server_type ?? $row->node_type ?? ''));
            $id = (int) ($row->id ?? $row->server_id ?? 0);
            $protocolMap->put($this->buildNodeKey($type, $id), $type === 'v2node' ? '未知' : $type);
            $modelClass = $modelMap[$type] ?? null;
            if ($modelClass && $id) {
                $groups[$modelClass][$id][$type] = true;
            }
        }

        foreach ($groups as $modelClass => $references) {
            $withProtocol = $modelClass === \App\Models\ServerV2node::class;
            $columns = $withProtocol ? ['id', 'name', 'protocol'] : ['id', 'name'];
            foreach (array_chunk(array_keys($references), 500) as $ids) {
                foreach ($modelClass::whereIn('id', $ids)->get($columns) as $server) {
                    // Aliases share a model read, not their public protocol identity.
                    foreach (array_keys($references[(int) $server->id]) as $type) {
                        $key = $this->buildNodeKey($type, $server->id);
                        if (!empty($server->name)) $nameMap->put($key, $server->name);
                        if ($withProtocol) $protocolMap->put($key, strtolower((string) $server->protocol) ?: '未知');
                    }
                }
            }
        }

        return [$nameMap, $protocolMap];
    }
}
