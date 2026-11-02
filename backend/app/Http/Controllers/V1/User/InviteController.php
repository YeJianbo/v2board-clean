<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InviteController extends Controller
{
    public function save(Request $request)
    {
        if ($request->isMethod('head')) abort(405);
        $saved = DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user['id'])->lockForUpdate()->first(['id']);
            if (!$user) abort(500, __('The user does not exist'));
            if (InviteCode::where('user_id', $user->id)->where('status', 0)->count() >= (int)config('v2board.invite_gen_limit', 5)) {
                abort(500, __('The maximum number of creations has been reached'));
            }
            $inviteCode = new InviteCode();
            $inviteCode->user_id = $user->id;
            $inviteCode->code = Helper::randomChar(8);
            if (!$inviteCode->save()) abort(500, __('Save failed'));
            return true;
        }, 3);
        return response([
            'data' => $saved
        ]);
    }

    public function details(Request $request)
    {
        $params = $request->validate([
            'current' => 'nullable|integer|min:1|max:1000000',
            'page_size' => 'nullable|integer|min:1|max:100',
        ]);
        $current = (int)($params['current'] ?? 1);
        $pageSize = max(10, (int)($params['page_size'] ?? 10));
        $builder = CommissionLog::where('invite_user_id', $request->user['id'])
            ->where('get_amount', '>', 0)
            ->select([
                'id',
                'trade_no',
                'order_amount',
                'get_amount',
                'created_at'
            ])
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
        $total = $builder->count();
        $details = ($current - 1) * $pageSize < $total
            ? $builder->forPage($current, $pageSize)->get()
            : collect();
        return response([
            'data' => $details,
            'total' => $total
        ]);
    }

    public function fetch(Request $request)
    {
        $codes = InviteCode::where('user_id', $request->user['id'])
            ->where('status', 0)
            ->get();
        $commission_rate = config('v2board.invite_commission', 10);
        $user = User::whereKey($request->user['id'])->select(['id', 'commission_rate', 'commission_balance'])
            ->selectSub(User::where('invite_user_id', $request->user['id'])->selectRaw('COUNT(*)'), 'invited_users')
            ->selectSub(CommissionLog::where('invite_user_id', $request->user['id'])->selectRaw('COALESCE(SUM(get_amount), 0)'), 'earned_commission')
            ->selectSub(Order::whereIn('status', [3, 4])->where('commission_status', 0)
                ->where('invite_user_id', $request->user['id'])->selectRaw('COALESCE(SUM(commission_balance), 0)'), 'pending_commission')
            ->first();
        if (!$user) abort(500, __('The user does not exist'));
        if ($user->commission_rate) {
            $commission_rate = $user->commission_rate;
        }
        $uncheck_commission_balance = (int)$user->pending_commission;
        if (config('v2board.commission_distribution_enable', 0)) {
            $uncheck_commission_balance = $uncheck_commission_balance * (config('v2board.commission_distribution_l1') / 100);
        }
        $stat = [
            //已注册用户数
            (int)$user->invited_users,
            //有效的佣金
            (int)$user->earned_commission,
            //确认中的佣金
            $uncheck_commission_balance,
            //佣金比例
            (int)$commission_rate,
            //可用佣金
            (int)$user->commission_balance
        ];
        return response([
            'data' => [
                'codes' => $codes,
                'stat' => $stat
            ]
        ]);
    }
}
