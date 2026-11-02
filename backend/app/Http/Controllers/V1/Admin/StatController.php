<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\ServerVless;
use App\Models\ServerAnytls;
use App\Models\ServerV2node;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getStat(Request $request)
    {
        $params = $request->validate([
            'start_at' => 'nullable|integer',
            'end_at' => 'nullable|integer',
        ]);

        if (!empty($params['start_at']) && !empty($params['end_at'])) {
            $fields = ['order_count', 'order_total', 'commission_count', 'commission_total',
                'paid_count', 'paid_total', 'register_count', 'invite_count', 'transfer_used_total'];
            $aggregates = ['COUNT(*) AS records'];
            foreach ($fields as $field) {
                // Traffic is stored as text; DECIMAL avoids MySQL's implicit DOUBLE sum.
                $value = $field === 'transfer_used_total' ? 'CAST(transfer_used_total AS DECIMAL(32, 0))' : $field;
                $aggregates[] = "SUM({$value}) AS {$field}";
            }
            $stats = Stat::where('record_at', '>=', $params['start_at'])
                ->where('record_at', '<', $params['end_at'])
                ->where('record_type', 'd')
                ->selectRaw(implode(', ', $aggregates))
                ->first();

            $data = [];
            if ((int) $stats->records > 0) {
                foreach ($fields as $field) {
                    $data[$field] = ($stats->$field ?? 0) + 0;
                }
            }

            return ['data' => $data];
        }

        return ['data' => (new StatisticalService())->generateStatData()];
    }

    public function getStatRecord(Request $request)
    {
        $params = $request->validate([
            'type' => 'required|in:paid_total,commission_total,register_count',
            'start_at' => 'nullable|integer',
            'end_at' => 'nullable|integer',
        ]);

        $service = new StatisticalService();
        if (!empty($params['start_at'])) {
            $service->setStartAt($params['start_at']);
        }
        if (!empty($params['end_at'])) {
            $service->setEndAt($params['end_at']);
        }

        return ['data' => $service->getStatRecord($params['type'])];
    }

    public function getRanking(Request $request)
    {
        $params = $request->validate([
            'type' => 'required|in:server_traffic_rank,user_consumption_rank,invite_rank',
            'start_at' => 'nullable|integer',
            'end_at' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $service = new StatisticalService();
        if (!empty($params['start_at'])) {
            $service->setStartAt($params['start_at']);
        }
        if (!empty($params['end_at'])) {
            $service->setEndAt($params['end_at']);
        }

        return ['data' => $service->getRanking($params['type'], $params['limit'] ?? 20)];
    }

    public function getOverride(Request $request)
    {
        $financial = StatisticalService::getFinancialSummary(time());
        return [
            'data' => [
                'online_user' => User::where('t','>=', time() - 600)
                    ->count(),
                'month_income' => $financial['current_month_income'],
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'day_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->where('reply_status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereIn('status', [3, 4])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => $financial['today_income'],
                'last_month_income' => $financial['last_month_income'],
                'commission_month_payout' => $financial['current_month_payout'],
                'commission_last_month_payout' => $financial['last_month_payout'],
            ]
        ];
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '注册人数',
                'date' => $date,
                'value' => $statistic['register_count']
            ];
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    public function getServerLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        return ['data' => $this->getServerTrafficRank($startAt, $endAt)];
    }

    public function getServerTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        return ['data' => $this->getServerTrafficRank($startAt, $endAt)];
    }

    private function getServerTrafficRank(int $startAt, int $endAt): array
    {
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        if (!$statistics) return [];
        $models = [
            'shadowsocks' => ServerShadowsocks::class,
            'v2ray' => ServerVmess::class,
            'vmess' => ServerVmess::class,
            'trojan' => ServerTrojan::class,
            'vless' => ServerVless::class,
            'tuic' => ServerTuic::class,
            'hysteria' => ServerHysteria::class,
            'anytls' => ServerAnytls::class,
            'v2node' => ServerV2node::class,
        ];
        $groups = [];
        foreach ($statistics as $index => $statistic) {
            $model = $models[strtolower((string) $statistic['server_type'])] ?? null;
            if ($model) $groups[$model][$index] = (int) $statistic['server_id'];
            $statistics[$index]['total'] /= 1073741824;
        }
        foreach ($groups as $model => $ids) {
            // Group by model so vmess/v2ray aliases share a single bounded lookup.
            $names = $model::whereNull('parent_id')->whereIn('id', array_values(array_unique($ids)))
                ->pluck('name', 'id');
            foreach ($ids as $index => $id) {
                if ($names->has($id)) $statistics[$index]['server_name'] = $names->get($id);
            }
        }
        return $statistics;
    }

    public function getUserTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        return ['data' => $this->getUserTrafficRank($startAt, $endAt)];
    }

    public function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        return ['data' => $this->getUserTrafficRank($startAt, $endAt)];
    }

    private function getUserTrafficRank(int $startAt, int $endAt): array
    {
        // Aggregate every rate bucket before selecting the top users.
        $statistics = StatUser::select([
            'user_id',
            DB::raw('SUM(u) as u'),
            DB::raw('SUM(d) as d'),
            DB::raw('SUM((u + d) * server_rate) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->orderBy('user_id')
            ->limit(15)
            ->get()
            ->toArray();
        if (!$statistics) return [];
        $emails = User::whereIn('id', array_column($statistics, 'user_id'))->pluck('email', 'id');
        foreach ($statistics as &$statistic) {
            $statistic['email'] = $emails[$statistic['user_id']] ?? 'null';
            $statistic['total'] /= 1073741824;
        }
        unset($statistic);
        return $statistics;
    }

    public function getStatUser(Request $request)
    {
        $params = $request->validate([
            'user_id' => 'required|integer|min:1',
            'current' => 'nullable|integer|min:1|max:1000000',
            'page' => 'nullable|integer|min:1|max:1000000',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $current = (int) ($params['current'] ?? $params['page'] ?? 1);
        $pageSize = max(10, (int) ($params['pageSize'] ?? 10));
        $builder = StatUser::where('user_id', $params['user_id']);

        $total = $builder->count();
        $records = ($current - 1) * $pageSize < $total
            ? $builder->orderByDesc('record_at')->orderByDesc('id')->forPage($current, $pageSize)->get()
            : collect();
        return [
            'data' => $records,
            'total' => $total
        ];
    }

}

