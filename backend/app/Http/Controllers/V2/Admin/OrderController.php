<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\ApiException;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderController extends Controller
{

    public function detail(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $order = Order::with(['user', 'plan', 'commission_log', 'invite_user'])->find($request->input('id'));
        if (!$order)
            return $this->fail([400202, '订单不存在']);
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        $order['period'] = (string) $order->period;
        return $this->success($order);
    }

    public function fetch(Request $request)
    {
        $fields = 'id,invite_user_id,user_id,plan_id,coupon_id,payment_id,type,period,trade_no,callback_no,total_amount,handling_amount,discount_amount,surplus_amount,refund_amount,balance_amount,surplus_order_ids,status,commission_status,commission_balance,actual_commission_balance,paid_at,created_at,updated_at';
        $request->validate([
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'is_commission' => 'sometimes|boolean',
            'filter' => 'sometimes|array|max:20',
            'filter.*' => 'array',
            'filter.*.id' => 'required|string|in:' . $fields,
            'filter.*.value' => ['present', function ($attribute, $value, $fail) {
                $values = is_array($value) ? $value : [$value];
                if (count($values) > 500) {
                    $fail('筛选列表最多包含500个值');
                    return;
                }
                foreach ($values as $item) {
                    if ((!is_null($item) && !is_scalar($item)) || (is_string($item) && mb_strlen($item) > 255)) {
                        $fail('筛选值必须是长度不超过255的文本、数字或它们组成的列表');
                        return;
                    }
                }
            }],
            'sort' => 'sometimes|array|max:10',
            'sort.*' => 'array',
            'sort.*.id' => 'required|string|in:' . $fields,
            'sort.*.desc' => 'required|boolean',
        ]);
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);
        $orderModel = Order::with('plan:id,name');

        if ($request->boolean('is_commission')) {
            $orderModel->whereNotNull('invite_user_id')
                ->whereNotIn('status', [0, 2])
                ->where('commission_balance', '>', 0);
        }

        $this->applyFiltersAndSorts($request, $orderModel);

        $total = (clone $orderModel)->toBase()->getCountForPagination();
        $orders = ($current - 1) * $pageSize < $total
            ? $orderModel->latest('created_at')->orderByDesc('id')->forPage($current, $pageSize)->get()
            : $orderModel->getModel()->newCollection();
        $paginatedResults = new LengthAwarePaginator($orders, $total, $pageSize, $current);

        $paginatedResults->getCollection()->transform(function ($order) {
            $orderArray = $order->toArray();
            $orderArray['period'] = (string) $order->period;
            return $orderArray;
        });

        return $this->paginate($paginatedResults);
    }

    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    private function applyFilters(Request $request, Builder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];

            $builder->where(function ($query) use ($field, $value) {
                $this->buildFilterQuery($query, $field, $value);
            });
        });
    }

    private function buildFilterQuery(Builder $query, string $field, mixed $value): void
    {
        // Handle array values for 'in' operations
        if (is_array($value)) {
            $query->whereIn($field, $value);
            return;
        }

        // Handle operator-based filtering
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);
        $operator = strtolower($operator);

        // Preserve textual identifiers; numeric columns coerce bound values in SQL.

        if ($operator === 'null') {
            $query->whereNull($field);
            return;
        }

        if ($operator === 'notnull') {
            $query->whereNotNull($field);
            return;
        }

        // Apply operator
        $query->where($field, match ($operator) {
            'eq' => '=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'notlike' => 'not like',
            default => 'like'
        }, match ($operator) {
            'like', 'notlike' => "%{$filterValue}%",
            default => $filterValue
        });
    }

    private function applySorting(Request $request, Builder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            return $this->fail([500, '更新失败']);
        }
        return $this->success(true);
    }

    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, '更新失败']);
        }
        return $this->success(true);
    }

    public function update(OrderUpdate $request)
    {
        $params = $request->only([
            'commission_status'
        ]);

        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }

        try {
            $updated = !$params || (new OrderService($order))->updateCommissionStatus((int)$params['commission_status']);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '更新失败']);
        }
        if (!$updated) return $this->fail([400, '订单佣金状态已变化或不允许此操作，确认前订单须已完成或折抵']);

        return $this->success(true);
    }

    public function assign(OrderAssign $request)
    {
        return DB::transaction(fn () => $this->assignOrder($request), 3);
    }

    private function assignOrder(OrderAssign $request)
    {
        $user = User::byEmail($request->input('email'))->lockForUpdate()->first();

        if (!$user) {
            return $this->fail([400202, '该用户不存在']);
        }

        $plan = Plan::find($request->input('plan_id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return $this->fail([400, '该用户还有待支付的订单，无法分配']);
        }

        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $period = $request->input('period');
        $order->period = (string) $period;
        $order->trade_no = Helper::guid();
        $order->total_amount = $request->input('total_amount');

        if ($order->period === 'reset_price') {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
            $order->type = Order::TYPE_UPGRADE;
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
            $order->type = Order::TYPE_RENEWAL;
        } else {
            $order->type = Order::TYPE_NEW_PURCHASE;
        }

        $orderService->setInvite($user);

        if (!$order->save()) {
            throw new ApiException(500, '订单创建失败');
        }

        return $this->success($order->trade_no);
    }
}
