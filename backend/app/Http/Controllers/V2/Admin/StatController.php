<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\StatUserServer;
use App\Models\StatUserServerHour;
use App\Models\StatUserServerMinute;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class StatController extends Controller
{
    private const DASHBOARD_STATS_CACHE_KEY = 'admin:dashboard:stats:v2';
    private const DASHBOARD_STATS_CACHE_SECONDS = 15;
    private const TRAFFIC_RANK_CACHE_SECONDS = 15;
    private const ONLINE_WINDOW_SECONDS = 600;

    private $service;
    public function __construct(StatisticalService $service)
    {
        $this->service = $service;
    }
    private function getNodeSummary(): array
    {
        return app(\App\Services\ServerService::class)->getNodeStatusSummary();
    }

    private function getOnlineUserSummary(): array
    {
        $summary = $this->onlineUsersQuery(time())
            ->selectRaw('COUNT(*) as users, SUM(' . $this->onlineDeviceCountSql() . ') as devices')
            ->first();

        return [
            'users' => (int) $summary->users,
            'devices' => (int) $summary->devices,
        ];
    }

    private function onlineUsersQuery(int $now)
    {
        return User::withoutConnectivityTests()->where('t', '>=', $now - self::ONLINE_WINDOW_SECONDS);
    }

    private function onlineDeviceCountSql(): string
    {
        // Older reporters do not send a device count; an active user has at least one device.
        return $this->hasUserOnlineCountColumn()
            ? 'CASE WHEN online_count > 0 THEN online_count ELSE 1 END'
            : '1';
    }

    private function hasUserOnlineCountColumn(): bool
    {
        return Cache::remember('schema:user:online_count', 86400, function () {
            return Schema::hasColumn((new User())->getTable(), 'online_count');
        });
    }

    private function getPeriodTrafficSummary(int $now): array
    {
        $todayStart = strtotime(date('Y-m-d', $now));
        $monthStart = strtotime(date('Y-m-01', $now));
        $traffic = StatServer::where('record_at', '>=', $monthStart)
            ->where('record_at', '<', $now)
            ->where('record_type', 'd')
            ->selectRaw(
                'SUM(CASE WHEN record_at >= ? THEN u ELSE 0 END) AS today_upload,
                 SUM(CASE WHEN record_at >= ? THEN d ELSE 0 END) AS today_download,
                 SUM(CASE WHEN record_at >= ? THEN u + d ELSE 0 END) AS today_total,
                 SUM(u) AS month_upload, SUM(d) AS month_download, SUM(u + d) AS month_total',
                [$todayStart, $todayStart, $todayStart]
            )->first();

        return [
            (object) ['upload' => $traffic->today_upload ?? 0, 'download' => $traffic->today_download ?? 0, 'total' => $traffic->today_total ?? 0],
            (object) ['upload' => $traffic->month_upload ?? 0, 'download' => $traffic->month_download ?? 0, 'total' => $traffic->month_total ?? 0],
        ];
    }

    public function getDetails(Request $request): array
    {
        $params = $request->validate([
            'metric' => 'required|in:users,online-users,online-devices,online-nodes',
            'page' => 'nullable|integer|min:1|max:1000000',
            'page_size' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
        ]);
        $metric = $params['metric'];
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 10);
        $search = trim($params['search'] ?? '');
        $now = time();
        $meta = ['as_of' => $now, 'online_window_seconds' => self::ONLINE_WINDOW_SECONDS];

        if ($metric === 'online-nodes') {
            $nodes = array_values(array_filter(
                app(\App\Services\ServerService::class)->getNodeStatusDetails(),
                static function ($node) use ($search) {
                    return $node['is_online'] && ($search === '' ||
                        mb_stripos($node['name'] . ' ' . $node['protocol'] . ' ' . $node['host'], $search) !== false);
                }
            ));
            $total = count($nodes);
            $page = min($page, max(1, (int) ceil($total / $pageSize)));
            $rows = array_slice($nodes, ($page - 1) * $pageSize, $pageSize);
        } else {
            $query = $metric === 'users' ? User::withoutConnectivityTests() : $this->onlineUsersQuery($now);
            if ($search !== '') {
                // Bind a literal substring, not SQL LIKE wildcards supplied by the search field.
                $query->where(function ($query) use ($search) {
                    $query->whereRaw('INSTR(email, ?) > 0', [$search]);
                    if (ctype_digit($search)) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            }
            if ($metric === 'users') {
                $total = (clone $query)->count();
            } else {
                $counts = (clone $query)->selectRaw('COUNT(*) as users, SUM(' . $this->onlineDeviceCountSql() . ') as devices')->first();
                $total = (int) $counts->users;
                $meta['online_users'] = $total;
                $meta['online_devices'] = (int) $counts->devices;
            }
            $page = min($page, max(1, (int) ceil($total / $pageSize)));
            $rows = $query->with('plan:id,name')
                ->select(['id', 'email', 'plan_id', 'banned', 't', 'u', 'd', 'created_at'])
                ->selectRaw($this->onlineDeviceCountSql() . ' as device_count')
                ->when($metric !== 'users', static fn ($query) => $query->orderByDesc('t'))
                ->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)->limit($pageSize)->get()
                ->map(static function ($user) use ($now) {
                    $online = (int) $user->t >= $now - self::ONLINE_WINDOW_SECONDS;
                    return [
                        'key' => 'user:' . $user->id,
                        'id' => (int) $user->id,
                        'email' => $user->email,
                        'plan_name' => $user->plan->name ?? null,
                        'is_online' => $online,
                        'banned' => (bool) $user->banned,
                        'device_count' => $online ? (int) $user->device_count : 0,
                        'last_active_at' => (int) $user->t,
                        'created_at' => (int) $user->created_at,
                        'upload' => (int) $user->u,
                        'download' => (int) $user->d,
                    ];
                })->all();
        }

        return [
            'data' => $rows,
            'meta' => $meta,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $pageSize,
        ];
    }



    public function getOverride(Request $request)
    {
        $financial = StatisticalService::getFinancialSummary(time());
        $nodeSummary = $this->getNodeSummary();
        $onlineNodes = $nodeSummary['online'];
        $onlineSummary = $this->getOnlineUserSummary();
        $onlineDevices = $onlineSummary['devices'];
        $onlineUsers = $onlineSummary['users'];

        [$todayTraffic, $monthTraffic] = $this->getPeriodTrafficSummary(time());

        // 获取总流量统计
        $totalTraffic = app(\App\Services\TrafficRetention::class)->serverTotal();

        return [
            'data' => [
                'month_income' => $financial['current_month_income'],
                'month_register_total' => User::withoutConnectivityTests()->where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::pendingReply()->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereIn('status', [3, 4])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => $financial['today_income'],
                'last_month_income' => $financial['last_month_income'],
                'commission_month_payout' => $financial['current_month_payout'],
                'commission_last_month_payout' => $financial['last_month_payout'],
                // 新增统计数据
                'online_nodes' => $onlineNodes,
                'total_nodes' => $nodeSummary['total'],
                'online_devices' => $onlineDevices,
                'online_users' => $onlineUsers,
                'today_traffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'month_traffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'total_traffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get order statistics with filtering and pagination
     *
     * @param Request $request
     * @return array
     */
    public function getOrder(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|in:paid_total,paid_count,commission_total,commission_count',
        ]);

        $query = Stat::where('record_type', 'd');

        // Apply date filters
        if ($request->input('start_date')) {
            $query->where('record_at', '>=', strtotime($request->input('start_date')));
        }
        if ($request->input('end_date')) {
            $query->where('record_at', '<=', strtotime($request->input('end_date') . ' 23:59:59'));
        }

        $statistics = $query->select(['record_at', 'paid_total', 'paid_count', 'commission_total', 'commission_count'])
            ->orderBy('record_at', 'DESC')
            ->get();

        $summary = [
            'paid_total' => 0,
            'paid_count' => 0,
            'commission_total' => 0,
            'commission_count' => 0,
            'start_date' => $request->input('start_date', date('Y-m-d', $statistics->last()?->record_at)),
            'end_date' => $request->input('end_date', date('Y-m-d', $statistics->first()?->record_at)),
            'avg_paid_amount' => 0,
            'avg_commission_amount' => 0
        ];

        $dailyStats = [];
        $type = $request->input('type');
        foreach ($statistics as $statistic) {
            $date = date('Y-m-d', $statistic['record_at']);

            // Update summary
            $summary['paid_total'] += $statistic['paid_total'];
            $summary['paid_count'] += $statistic['paid_count'];
            $summary['commission_total'] += $statistic['commission_total'];
            $summary['commission_count'] += $statistic['commission_count'];

            if ($type) {
                $dailyStats[] = [
                    'date' => $date,
                    'value' => $statistic[$type],
                    'type' => $this->getTypeLabel($type)
                ];
            } else {
                $dailyStats[] = [
                    'date' => $date,
                    'paid_total' => $statistic['paid_total'],
                    'paid_count' => $statistic['paid_count'],
                    'commission_total' => $statistic['commission_total'],
                    'commission_count' => $statistic['commission_count'],
                    'avg_order_amount' => $statistic['paid_count'] > 0 ? round($statistic['paid_total'] / $statistic['paid_count'], 2) : 0,
                    'avg_commission_amount' => $statistic['commission_count'] > 0 ? round($statistic['commission_total'] / $statistic['commission_count'], 2) : 0
                ];
            }
        }

        // Calculate averages for summary
        if ($summary['paid_count'] > 0) {
            $summary['avg_paid_amount'] = round($summary['paid_total'] / $summary['paid_count'], 2);
        }
        if ($summary['commission_count'] > 0) {
            $summary['avg_commission_amount'] = round($summary['commission_total'] / $summary['commission_count'], 2);
        }

        // Add percentage calculations to summary
        $summary['commission_rate'] = $summary['paid_total'] > 0
            ? round(($summary['commission_total'] / $summary['paid_total']) * 100, 2)
            : 0;

        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'list' => array_reverse($dailyStats),
                'summary' => $summary,
            ]
        ];
    }

    /**
     * Get human readable label for statistic type
     *
     * @param string $type
     * @return string
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type
        };
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $data = $this->service->getServerRank();
        return $this->success(data: $data);
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $data = $this->service->getServerRank('yesterday');
        return $this->success($data);
    }

    public function getStatUser(Request $request)
    {
        $params = $request->validate([
            'user_id' => 'required|integer|min:1',
            'current' => 'nullable|integer|min:1|max:1000000',
            'page' => 'nullable|integer|min:1|max:1000000',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
        ]);
        abort_if(isset($params['start_time'], $params['end_time']) && $params['end_time'] < $params['start_time'],
            422, '结束时间不能早于开始时间');

        $current = (int) ($params['current'] ?? $params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);
        $recordsQuery = StatUser::where('user_id', $params['user_id']);

        if ($request->input('start_time')) {
            $recordsQuery->where('record_at', '>=', (int) $request->input('start_time'));
        }
        if ($request->input('end_time')) {
            $recordsQuery->where('record_at', '<=', (int) $request->input('end_time'));
        }

        $total = $recordsQuery->count();
        $data = ($current - 1) * $pageSize < $total
            ? $recordsQuery->orderByDesc('record_at')->orderByDesc('id')->forPage($current, $pageSize)->get()->all()
            : [];
        return [
            'data' => $data,
            'total' => $total,
        ];
    }

    public function getUserNodeTraffic(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        $userId = (int) $request->input('user_id');
        $statsQuery = StatUserServer::where('user_id', $userId);

        if ($request->input('start_time')) {
            $statsQuery->where('record_at', '>=', (int) $request->input('start_time'));
        }
        if ($request->input('end_time')) {
            $statsQuery->where('record_at', '<=', (int) $request->input('end_time'));
        }

        $range = (clone $statsQuery)
            ->selectRaw('MIN(record_at) as start_at, MAX(record_at) as end_at, SUM(u + d) as user_total')
            ->first();

        if (!$range || !$range->start_at || !$range->end_at) {
            return [
                'data' => [],
                'meta' => [
                    'start_at' => null,
                    'end_at' => null,
                    'user_total' => 0,
                    'note' => '节点维度流量从新版部署后开始统计，历史数据无法拆分到节点。',
                ],
            ];
        }

        $rows = (clone $statsQuery)
            ->selectRaw('server_id as id, server_type, MAX(server_rate) as rate, SUM(u) as u, SUM(d) as d, SUM(u + d) as total, SUM((u + d) * server_rate) as cost')
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->get();

        [$names, $protocols] = $this->resolveNodeRankMetadata($rows, true);

        $data = $rows->map(function ($row) use ($names, $protocols) {
            $key = $this->buildNodeRankKey($row->server_type ?? null, $row->id);
            return [
                'id' => (int) $row->id,
                'key' => $key,
                'server_type' => strtolower((string) ($row->server_type ?? '')),
                'type' => (string) ($protocols[$key] ?? $row->server_type),
                'name' => $names[$key] ?? "Node {$row->id}",
                'rate' => (string) ($row->rate ?? '1.00'),
                'u' => (int) $row->u,
                'd' => (int) $row->d,
                'total' => (int) $row->total,
                'cost' => (float) ($row->cost ?? 0),
            ];
        })->values();

        return [
            'data' => $data,
            'meta' => [
                'start_at' => (int) $range->start_at,
                'end_at' => (int) $range->end_at,
                'user_total' => (int) $range->user_total,
                'note' => null,
            ],
        ];
    }

    public function getUserNodeTrafficSeries(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'granularity' => 'nullable|in:minute,two_minutes,hour,day',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'node_keys' => 'nullable|array|max:500',
            'node_keys.*' => 'nullable|string|max:64',
            'include_total' => 'nullable|boolean',
        ]);

        $userId = (int) $request->input('user_id');
        $granularity = (string) ($request->input('granularity') ?: 'day');
        $includeTotal = $request->boolean('include_total', true);
        $nodeKeys = collect($request->input('node_keys', []))
            ->map(function ($value) {
                return trim((string) $value);
            })
            ->filter()
            ->values();

        if (in_array($granularity, ['minute', 'two_minutes'], true)) {
            $model = StatUserServerMinute::query();
            $step = $granularity === 'two_minutes' ? 120 : 60;
            $format = 'H:i';
            $endAt = intdiv((int) $request->input('end_time', time()), $step) * $step;
            $startAt = $request->input('start_time')
                ? intdiv((int) $request->input('start_time'), $step) * $step
                : $endAt - 59 * $step;
            $maxRange = 24 * 3600;
            $note = '分钟曲线从当前版本部署后开始统计。';
        } elseif ($granularity === 'hour') {
            $model = StatUserServerHour::query();
            $step = 3600;
            $format = 'm-d H:00';
            $endAt = strtotime(date('Y-m-d H:00:00', (int) $request->input('end_time', time())));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d H:00:00', (int) $request->input('start_time')))
                : $endAt - 47 * $step;
            $maxRange = 31 * 24 * 3600;
            $note = '小时曲线从新版部署后开始统计。';
        } else {
            $model = StatUserServer::query();
            $step = 86400;
            $format = 'm-d';
            $endAt = strtotime(date('Y-m-d', (int) $request->input('end_time', time())));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d', (int) $request->input('start_time')))
                : $endAt - 29 * $step;
            $maxRange = 366 * 86400;
            $note = null;
        }

        if ($startAt > $endAt) {
            [$startAt, $endAt] = [$endAt, $startAt];
        }
        if (($endAt - $startAt) > $maxRange) {
            $startAt = $endAt - $maxRange;
        }

        $baseQuery = $model
            ->where('user_id', $userId)
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<=', $endAt + ($granularity === 'two_minutes' ? $step - 1 : 0));

        $requestedNodePairs = $nodeKeys
            ->map(function ($nodeKey) {
                [$type, $id] = array_pad(explode(':', strtolower((string) $nodeKey), 2), 2, null);
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

        $labels = [];
        for ($cursor = $startAt; $cursor <= $endAt; $cursor += $step) {
            $labels[] = [
                'timestamp' => $cursor,
                'label' => date($format, $cursor),
            ];
        }

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
        $nodeMetadata = [];
        $totalPointMap = $includeTotal ? $emptyPoints : [];
        $twoMinutes = $granularity === 'two_minutes';
        $recordAtSql = $twoMinutes ? '(record_at - (record_at % 120))' : 'record_at';
        // Keep first-appearance order when adjacent minutes share one response bucket.
        $firstRecordSql = $twoMinutes ? ', MIN(record_at) as first_record_at' : '';
        // Keep only the response points, not an additional collection of every Eloquent row.
        foreach ($baseQuery
            ->selectRaw("server_id, server_type, {$recordAtSql} as record_at{$firstRecordSql}, SUM(u) as u, SUM(d) as d, SUM((u + d) * server_rate) as cost")
            ->groupBy('server_id', 'server_type')->groupByRaw($recordAtSql)
            ->orderBy($twoMinutes ? 'first_record_at' : 'record_at')->cursor() as $row) {
            $key = $this->buildNodeRankKey($row->server_type ?? null, $row->server_id);
            if (!isset($seriesMap[$key])) {
                $seriesMap[$key] = [
                    'key' => $key,
                    'id' => (int) $row->server_id,
                    'points' => $emptyPoints,
                ];
                $nodeMetadata[$key] = (object) [
                    'server_type' => $row->server_type,
                    'server_id' => $row->server_id,
                ];
            }
            $recordAt = (int) $row->record_at;
            if (!isset($seriesMap[$key]['points'][$recordAt])) {
                continue;
            }

            $u = (int) ($row->u ?? 0);
            $d = (int) ($row->d ?? 0);
            $seriesMap[$key]['points'][$recordAt]['u'] += $u;
            $seriesMap[$key]['points'][$recordAt]['d'] += $d;
            $seriesMap[$key]['points'][$recordAt]['total'] += $u + $d;
            $seriesMap[$key]['points'][$recordAt]['cost'] += (float) ($row->cost ?? 0);
            if ($includeTotal) {
                $totalPointMap[$recordAt]['u'] += $u;
                $totalPointMap[$recordAt]['d'] += $d;
                $totalPointMap[$recordAt]['total'] += $u + $d;
                $totalPointMap[$recordAt]['cost'] += (float) ($row->cost ?? 0);
            }
        }

        if (!$seriesMap) {
            return [
                'data' => [
                    'labels' => [],
                    'series' => [],
                ],
                'meta' => [
                    'granularity' => $granularity,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'note' => $note ?: '暂无节点维度曲线数据。',
                ],
            ];
        }

        [$names, $protocols] = $this->resolveNodeRankMetadata($nodeMetadata, true);
        $keysToRender = $requestedNodePairs->isNotEmpty()
            ? $requestedNodePairs->pluck('key')->unique()->filter(fn ($key) => isset($seriesMap[$key]))->values()
            : array_keys($seriesMap);
        $orderedSeries = [];
        foreach ($keysToRender as $key) {
            $seriesMap[$key]['type'] = (string) ($protocols[$key] ?? explode(':', $key, 2)[0]);
            $seriesMap[$key]['name'] = (string) ($names[$key] ?? ('Node ' . $seriesMap[$key]['id']));
            $orderedSeries[$key] = $seriesMap[$key];
        }
        $seriesMap = $orderedSeries;
        unset($orderedSeries, $nodeMetadata);

        $series = collect($seriesMap)->map(function ($seriesItem) {
            // The zero-filled timestamps were inserted in ascending order; replacing values preserves it.
            $points = collect(array_values($seriesItem['points']));
            $total = (int) $points->sum('total');
            $cost = (float) $points->sum('cost');

            return [
                'key' => $seriesItem['key'],
                'id' => $seriesItem['id'],
                'type' => $seriesItem['type'],
                'name' => $seriesItem['name'],
                'total' => $total,
                'cost' => $cost,
                'points' => $points,
            ];
        })->values();

        if ($includeTotal) {
            $totalPoints = collect(array_values($totalPointMap));

            $series->prepend([
                'key' => 'total',
                'id' => 0,
                'type' => 'all',
                'name' => '全部节点',
                'total' => (int) $totalPoints->sum('total'),
                'cost' => (float) $totalPoints->sum('cost'),
                'points' => $totalPoints,
            ]);
        }

        return [
            'data' => [
                'labels' => array_map(function ($item) {
                    return $item['label'];
                }, $labels),
                'series' => $series->values(),
            ],
            'meta' => [
                'granularity' => $granularity,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'note' => $note,
            ],
        ];
    }

    public function getNodeUserTraffic(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer',
            'server_type' => 'required|string|max:32',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $serverId = (int) $request->input('server_id');
        $serverType = strtolower((string) $request->input('server_type'));
        $limit = min(max((int) $request->input('limit', 20), 1), 100);
        $statsQuery = StatUserServer::where('server_id', $serverId)
            ->where('server_type', $serverType);

        if ($request->input('start_time')) {
            $statsQuery->where('record_at', '>=', (int) $request->input('start_time'));
        }
        if ($request->input('end_time')) {
            $statsQuery->where('record_at', '<=', (int) $request->input('end_time'));
        }

        $range = (clone $statsQuery)
            ->selectRaw('MIN(record_at) as start_at, MAX(record_at) as end_at, SUM(u + d) as node_total')
            ->first();

        if (!$range || !$range->start_at || !$range->end_at) {
            return [
                'data' => [],
                'meta' => [
                    'start_at' => null,
                    'end_at' => null,
                    'node_total' => 0,
                    'note' => '节点用户排行从新版部署后开始统计，历史数据无法拆分到用户。',
                ],
            ];
        }

        $rows = (clone $statsQuery)
            ->selectRaw('user_id, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->all())
            ->get(['id', 'email'])
            ->keyBy('id');

        $data = $rows->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);
            return [
                'user_id' => (int) $row->user_id,
                'email' => $user ? $user->email : "User {$row->user_id}",
                'u' => (int) $row->u,
                'd' => (int) $row->d,
                'total' => (int) $row->total,
            ];
        })->values();

        return [
            'data' => $data,
            'meta' => [
                'start_at' => (int) $range->start_at,
                'end_at' => (int) $range->end_at,
                'node_total' => (int) $range->node_total,
                'note' => null,
            ],
        ];
    }

    public function getNodeTrafficSeries(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer',
            'server_type' => 'required|string|max:32',
            'granularity' => 'nullable|in:hour,day',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        $serverId = (int) $request->input('server_id');
        $serverType = strtolower((string) $request->input('server_type'));
        $granularity = $request->input('granularity') === 'hour' ? 'hour' : 'day';
        $endInput = (int) $request->input('end_time', time());

        if ($granularity === 'hour') {
            $model = StatUserServerHour::query();
            $step = 3600;
            $format = 'm-d H:00';
            $endAt = strtotime(date('Y-m-d H:00:00', $endInput));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d H:00:00', (int) $request->input('start_time')))
                : $endAt - 23 * $step;
            $maxRange = 14 * 24 * $step;
        } else {
            $model = StatUserServer::query();
            $step = 86400;
            $format = 'm-d';
            $endAt = strtotime(date('Y-m-d', $endInput));
            $startAt = $request->input('start_time')
                ? strtotime(date('Y-m-d', (int) $request->input('start_time')))
                : $endAt - 29 * $step;
            $maxRange = 366 * $step;
        }

        if ($startAt > $endAt) {
            [$startAt, $endAt] = [$endAt, $startAt];
        }
        if (($endAt - $startAt) > $maxRange) {
            $startAt = $endAt - $maxRange;
        }

        $rows = $model
            ->where('server_id', $serverId)
            ->where('server_type', $serverType)
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<=', $endAt)
            ->selectRaw('record_at, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->groupBy('record_at')
            ->orderBy('record_at')
            ->get()
            ->keyBy('record_at');

        $points = [];
        for ($cursor = $startAt; $cursor <= $endAt; $cursor += $step) {
            $row = $rows->get($cursor);
            $u = (int) ($row->u ?? 0);
            $d = (int) ($row->d ?? 0);
            $points[] = [
                'timestamp' => $cursor,
                'label' => date($format, $cursor),
                'u' => $u,
                'd' => $d,
                'total' => $u + $d,
            ];
        }

        return [
            'data' => $points,
            'meta' => [
                'granularity' => $granularity,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'total' => array_sum(array_column($points, 'total')),
                'note' => $granularity === 'hour'
                    ? '小时曲线从新版部署后开始统计，部署前无法按小时拆分。'
                    : null,
            ],
        ];
    }

    public function getStatRecord(Request $request)
    {
        $request->validate([
            'type' => 'required|in:paid_total,commission_total,register_count',
            'start_at' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_at' => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        if ($request->input('start_at')) {
            $this->service->setStartAt((int) $request->input('start_at'));
        }

        if ($request->input('end_at')) {
            $this->service->setEndAt((int) $request->input('end_at'));
        }

        return [
            'data' => $this->service->getStatRecord($request->input('type'))
        ];
    }

    public function getRanking(Request $request)
    {
        $params = $request->validate([
            'type' => 'required|in:server_traffic_rank,user_consumption_rank,invite_rank',
            'start_at' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_at' => 'nullable|integer|min:1000000000|max:9999999999',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if (!empty($params['start_at'])) {
            $this->service->setStartAt($params['start_at']);
        }
        if (!empty($params['end_at'])) {
            $this->service->setEndAt($params['end_at']);
        }

        return [
            'data' => $this->service->getRanking($params['type'], $params['limit'] ?? 20),
        ];
    }

    /**
     * Get comprehensive statistics data including income, users, and growth rates
     */
    public function getStats()
    {
        return Cache::remember(
            self::DASHBOARD_STATS_CACHE_KEY,
            self::DASHBOARD_STATS_CACHE_SECONDS,
            fn () => $this->buildStats()
        );
    }

    private function buildStats(): array
    {
        $now = time();
        $currentMonthStart = strtotime(date('Y-m-01', $now));
        $lastMonthStart = strtotime('-1 month', $currentMonthStart);

        // 获取在线节点数
        $nodeSummary = $this->getNodeSummary();
        $onlineNodes = $nodeSummary['online'];

        [$todayTraffic, $monthTraffic] = $this->getPeriodTrafficSummary($now);

        // 获取总流量统计
        $totalTraffic = app(\App\Services\TrafficRetention::class)->serverTotal();

        $financial = StatisticalService::getFinancialSummary($now, true);
        $todayIncome = $financial['today_income'];
        $yesterdayIncome = $financial['yesterday_income'];
        $currentMonthIncome = $financial['current_month_income'];
        $lastMonthIncome = $financial['last_month_income'];
        $twoMonthsAgoIncome = $financial['two_months_ago_income'];
        $currentMonthCommissionPayout = $financial['current_month_payout'];
        $lastMonthCommissionPayout = $financial['last_month_payout'];
        $twoMonthsAgoCommission = $financial['two_months_ago_payout'];

        $users = User::withoutConnectivityTests()->selectRaw(
            'COUNT(*) AS total_users,
             SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS current_month_count,
             SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS last_month_count,
             SUM(CASE WHEN expired_at >= ? OR expired_at IS NULL THEN 1 ELSE 0 END) AS active_users,
             SUM(CASE WHEN t >= ? THEN 1 ELSE 0 END) AS online_users,
             SUM(CASE WHEN t >= ? THEN ' . $this->onlineDeviceCountSql() . ' ELSE 0 END) AS online_devices',
            [$currentMonthStart, $now, $lastMonthStart, $currentMonthStart, $now,
                $now - self::ONLINE_WINDOW_SECONDS, $now - self::ONLINE_WINDOW_SECONDS]
        )->first();
        $currentMonthNewUsers = (int) $users->current_month_count;
        $lastMonthNewUsers = (int) $users->last_month_count;
        $totalUsers = (int) $users->total_users;
        $activeUsers = (int) $users->active_users;
        $onlineUsers = (int) $users->online_users;
        $onlineDevices = (int) $users->online_devices;

        // Calculate growth rates
        $monthIncomeGrowth = $lastMonthIncome > 0 ? round(($currentMonthIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : 0;
        $lastMonthIncomeGrowth = $twoMonthsAgoIncome > 0 ? round(($lastMonthIncome - $twoMonthsAgoIncome) / $twoMonthsAgoIncome * 100, 1) : 0;
        $commissionGrowth = $twoMonthsAgoCommission > 0 ? round(($lastMonthCommissionPayout - $twoMonthsAgoCommission) / $twoMonthsAgoCommission * 100, 1) : 0;
        $userGrowth = $lastMonthNewUsers > 0 ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1) : 0;
        $dayIncomeGrowth = $yesterdayIncome > 0 ? round(($todayIncome - $yesterdayIncome) / $yesterdayIncome * 100, 1) : 0;

        // 获取待处理工单和佣金数据
        $ticketPendingTotal = Ticket::pendingReply()->count();
        $commissionPendingTotal = Order::where('commission_status', 0)
            ->where('invite_user_id', '!=', NULL)
            ->whereIn('status', [3, 4])
            ->where('commission_balance', '>', 0)
            ->count();

        return [
            'data' => [
                // 收入相关
                'todayIncome' => $todayIncome,
                'dayIncomeGrowth' => $dayIncomeGrowth,
                'currentMonthIncome' => $currentMonthIncome,
                'lastMonthIncome' => $lastMonthIncome,
                'monthIncomeGrowth' => $monthIncomeGrowth,
                'lastMonthIncomeGrowth' => $lastMonthIncomeGrowth,

                // 佣金相关
                'currentMonthCommissionPayout' => $currentMonthCommissionPayout,
                'lastMonthCommissionPayout' => $lastMonthCommissionPayout,
                'commissionGrowth' => $commissionGrowth,
                'commissionPendingTotal' => $commissionPendingTotal,

                // 用户相关
                'currentMonthNewUsers' => $currentMonthNewUsers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'userGrowth' => $userGrowth,
                'onlineUsers' => $onlineUsers,
                'onlineDevices' => $onlineDevices,

                // 工单相关
                'ticketPendingTotal' => $ticketPendingTotal,

                // 节点相关
                'onlineNodes' => $onlineNodes,
                'totalNodes' => $nodeSummary['total'],

                // 流量统计
                'todayTraffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'monthTraffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'totalTraffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficHistory(Request $request)
    {
        $params = $request->validate([
            'period' => 'required|in:today,month,all', 'interval' => 'required|in:hour,day,month',
            'group_by' => 'sometimes|in:node,user',
            'node' => ['nullable', 'regex:/^[a-zA-Z0-9_-]{1,32}:\d+$/D'],
            'user_id' => 'nullable|integer|min:1',
        ]);
        abort_if($params['interval'] === 'hour' && $params['period'] !== 'today', 422, '小时统计仅用于今日流量');
        $groupBy = $params['group_by'] ?? 'node';
        abort_if($groupBy === 'user' && isset($params['node']), 422, '按用户统计不能筛选节点');
        abort_if($groupBy === 'node' && isset($params['user_id']), 422, '按节点统计不能筛选用户');
        $now = now()->getTimestamp();
        $periodKey = $params['period'] === 'all' ? 'all'
            : date($params['period'] === 'month' ? 'Y-m' : 'Y-m-d', $now);
        $cacheKey = 'dashboard:traffic-history:v7:' . implode(':', [
            $params['period'], $params['interval'], $groupBy, $params['node'] ?? '', $params['user_id'] ?? '',
            $periodKey,
        ]);
        $data = Cache::remember($cacheKey, 60, function () use ($params, $groupBy, $now) {
            if ($groupBy === 'user') {
                $data = app(\App\Services\DashboardTrafficHistory::class)->readUsers($params['period'], $params['interval'], $params['user_id'] ?? null, $now);
                $emails = User::whereIn('id', array_column($data['users'], 'id'))->pluck('email', 'id');
                foreach ($data['users'] as &$user) {
                    $user['name'] = $user['id'] ? ($emails[$user['id']] ?? '用户 #' . $user['id']) : '未归属用户';
                }
                if (isset($params['user_id'])) {
                    $names = $this->resolveNodeRankNames(collect($data['nodes'])->map(fn ($row) => (object) $row));
                    foreach ($data['nodes'] as &$node) {
                        $node['name'] = $node['id'] ? ($names[$this->buildNodeRankKey($node['server_type'], $node['id'])] ?? $node['key']) : '未分配节点';
                    }
                }
                return $data;
            }
            $data = app(\App\Services\DashboardTrafficHistory::class)->read($params['period'], $params['interval'], $params['node'] ?? null, $now);
            $names = $this->resolveNodeRankNames(collect($data['nodes'])->map(fn ($row) => (object) $row));
            foreach ($data['nodes'] as &$node) {
                $node['name'] = $node['id'] ? ($names[$this->buildNodeRankKey($node['server_type'], $node['id'])] ?? $node['key']) : '未分配历史流量';
            }
            return $data;
        });
        return ['data' => $data];
    }

    public function getTrafficRank(Request $request)
    {
        $params = $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'cache_live' => 'nullable|boolean',
        ]);

        $type = $params['type'];
        $startDate = (int) ($params['start_time'] ?? strtotime('-7 days'));
        $endDate = (int) ($params['end_time'] ?? time());
        abort_if($endDate < $startDate, 422, '结束时间不能早于开始时间');
        $now = time();
        $live = $request->boolean('cache_live') && $endDate <= $now
            && $endDate >= $now - self::TRAFFIC_RANK_CACHE_SECONDS * 2;
        $bucketEnd = intdiv($endDate, self::TRAFFIC_RANK_CACHE_SECONDS) * self::TRAFFIC_RANK_CACHE_SECONDS;
        if ($live && $bucketEnd >= $startDate) {
            // Both the query and cache key use the same live-window boundary.
            $endDate = $bucketEnd;
        } else {
            $live = false;
        }
        $cacheKey = sprintf(
            'admin:dashboard:traffic-rank:v3:%s:%s:%d:%d',
            $type,
            $live ? 'live' : 'exact',
            $startDate,
            $endDate
        );

        return Cache::remember(
            $cacheKey,
            self::TRAFFIC_RANK_CACHE_SECONDS,
            fn () => $this->buildTrafficRank($type, $startDate, $endDate)
        );
    }

    private function buildTrafficRank(string $type, int $startDate, int $endDate): array
    {
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;
        $firstDay = new \DateTimeImmutable(date('Y-m-d', $startDate));
        if ($firstDay->getTimestamp() === $startDate) {
            // Presets compare daily aggregates, including today's partial day.
            $lastDay = new \DateTimeImmutable(date('Y-m-d', $endDate));
            $days = (int) $firstDay->diff($lastDay)->format('%a') + 1;
            $previousStartDate = $firstDay->modify("-{$days} days")->getTimestamp();
        }

        if ($type === 'node') {
            // Get node traffic data
            $currentData = StatServer::selectRaw('server_id as id, server_type, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->where('record_type', 'd')
                ->groupBy('server_id', 'server_type')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();
            if ($currentData->isEmpty()) return ['timestamp' => date('c'), 'data' => []];

            // Get previous period data for comparison
            $previousData = StatServer::selectRaw('server_id as id, server_type, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->where('record_type', 'd')
                ->whereIn('server_id', $currentData->pluck('id'))
                ->whereIn('server_type', $currentData->pluck('server_type')->filter()->unique()->values())
                ->groupBy('server_id', 'server_type')
                ->get()
                ->keyBy(function ($item) {
                    return $this->buildNodeRankKey($item->server_type, $item->id);
                });

        } else {
            // Get user traffic data
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->where('record_type', 'd')
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();
            if ($currentData->isEmpty()) return ['timestamp' => date('c'), 'data' => []];

            // Get previous period data for comparison
            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->where('record_type', 'd')
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        if ($type === 'node') {
            [$names, $protocols] = $this->resolveNodeRankMetadata($currentData, true);
        } else {
            $names = User::whereIn('id', $currentData->pluck('id'))->pluck('email', 'id');
            $protocols = collect();
        }

        foreach ($currentData as $data) {
            $previousKey = $type === 'node'
                ? $this->buildNodeRankKey($data->server_type, $data->id)
                : $data->id;
            $previousValue = isset($previousData[$previousKey]) ? $previousData[$previousKey]->value : 0;
            $hasComparison = $previousValue > 0;
            $change = $hasComparison ? round(($data->value - $previousValue) / $previousValue * 100, 1) : null;

            $result[] = [
                'id' => (string) $data->id,
                'type' => $type === 'node' ? ($protocols[$this->buildNodeRankKey($data->server_type ?? null, $data->id)] ?? strtolower((string) ($data->server_type ?? ''))) : '',
                'name' => $names[$this->buildNodeRankKey($data->server_type ?? null, $data->id)] ?? $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'hasComparison' => $hasComparison,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }

    private function buildNodeRankKey(?string $type, $id): string
    {
        return strtolower((string) $type) . ':' . (string) $id;
    }

    private function getNodeRankRowId($item): int
    {
        return (int) ($item->id ?? $item->server_id ?? 0);
    }

    private function resolveNodeRankNames($rows)
    {
        return $this->resolveNodeRankMetadata($rows)[0];
    }

    private function resolveNodeRankMetadata($rows, bool $includeProtocols = false): array
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
            $type = strtolower((string) $row->server_type);
            $id = $this->getNodeRankRowId($row);
            if ($includeProtocols && $type !== 'v2node') {
                $protocolMap->put($this->buildNodeRankKey($type, $id), $type);
            }
            $modelClass = $modelMap[$type] ?? null;
            if ($modelClass && $id) {
                $groups[$modelClass][] = ['type' => $type, 'id' => $id];
            }
        }
        foreach ($groups as $modelClass => $items) {
            $withProtocol = $includeProtocols && $modelClass === \App\Models\ServerV2node::class;
            $columns = $withProtocol ? ['id', 'name', 'protocol'] : ['id', 'name'];
            // Names and protocols share one bounded read; vmess/v2ray share one model.
            $servers = $modelClass::whereIn('id', array_values(array_unique(array_column($items, 'id'))))
                ->get($columns)->keyBy('id');
            foreach ($items as $item) {
                $key = $this->buildNodeRankKey($item['type'], $item['id']);
                $server = $servers->get($item['id']);
                if ($server && !empty($server->name)) $nameMap->put($key, $server->name);
                if ($withProtocol) {
                    $protocol = strtolower((string) ($server->protocol ?? 'v2node'));
                    $protocolMap->put($key, $protocol ?: 'v2node');
                }
            }
        }
        return [$nameMap, $protocolMap];
    }
}
