<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderFetch;
use App\Http\Requests\Admin\OrderUpdate;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    private function filter(Request $request, &$builder)
    {
        if ($request->input('filter')) {
            foreach ($request->input('filter') as $filter) {
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                if ($filter['key'] === 'email') {
                    $builder->whereHas('user', function ($query) use ($filter) {
                        $query->where('email', $filter['condition'], $filter['value']);
                    });
                    continue;
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    public function detail(Request $request)
    {
        $order = Order::find($request->input('id'));
        if (!$order) abort(500, '订单不存在');
        $order['commission_log'] = CommissionLog::where('trade_no', $order->trade_no)->get();
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return response([
            'data' => $order
        ]);
    }

    public function fetch(OrderFetch $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $orderModel = Order::orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
        if ($request->input('is_commission')) {
            $orderModel->where('invite_user_id', '!=', NULL);
            $orderModel->whereNotIn('status', [0, 2]);
            $orderModel->where('commission_balance', '>', 0);
        }
        $this->filter($request, $orderModel);
        $total = $orderModel->count();
        $res = ($current - 1) * $pageSize >= $total
            ? collect()
            : $orderModel->forPage($current, $pageSize)->get();
        $planIds = $res->pluck('plan_id')->filter()->unique()->all();
        $planNames = $planIds ? Plan::whereIn('id', $planIds)->pluck('name', 'id') : collect();
        foreach ($res as $order) {
            if ($planNames->has($order->plan_id)) $order['plan_name'] = $planNames->get($order->plan_id);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, '订单不存在');
        }
        if ($order->status !== 0) abort(500, '只能对待支付的订单进行操作');

        $orderService = new OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            abort(500, '更新失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, '订单不存在');
        }
        if ($order->status !== 0) abort(500, '只能对待支付的订单进行操作');

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            abort(500, '更新失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function update(OrderUpdate $request)
    {
        $params = $request->only([
            'commission_status'
        ]);

        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, '订单不存在');
        }

        try {
            $updated = !$params || (new OrderService($order))->updateCommissionStatus((int)$params['commission_status']);
        } catch (\Exception $e) {
            abort(500, '更新失败');
        }
        if (!$updated) abort(400, '订单佣金状态已变化或不允许此操作，确认前订单须已完成或折抵');

        return response([
            'data' => true
        ]);
    }

    public function assign(OrderAssign $request)
    {
        return DB::transaction(fn () => $this->assignOrder($request), 3);
    }

    private function assignOrder(OrderAssign $request)
    {
        $user = User::where('email', $request->input('email'))->lockForUpdate()->first();

        if (!$user) {
            abort(500, '该用户不存在');
        }

        $plan = Plan::find($request->input('plan_id'));
        if (!$plan) {
            abort(500, '该订阅不存在');
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            abort(500, '该用户还有待支付的订单，无法分配');
        }

        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = $request->input('period');
        $order->trade_no = Helper::guid();
        $order->total_amount = $request->input('total_amount');

        if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
            $order->type = 3;
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
            $order->type = 2;
        } else {
            $order->type = 1;
        }

        $orderService->setInvite($user);

        if (!$order->save()) {
            abort(500, '订单创建失败');
        }

        return response([
            'data' => $order->trade_no
        ]);
    }
}
