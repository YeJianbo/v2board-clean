<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;
    private bool $markedPaid = false;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        return DB::transaction(function () {
            $order = Order::whereKey($this->order->id)->lockForUpdate()->first();
            if (!$order) {
                return false;
            }
            if ((int)$order->status === 3) {
                return true;
            }
            if ((int)$order->status !== 1) {
                return false;
            }

            $this->order = $order;
            $this->user = User::whereKey($order->user_id)->lockForUpdate()->first();
            if (!$this->user) {
                throw new \RuntimeException('订单用户不存在');
            }

            if ((int)$order->type === 9) {
                $this->user->balance += $order->total_amount + $this->getbounus($order->total_amount);
                if (!$this->user->save()) {
                    throw new \RuntimeException('充值失败');
                }
                $order->status = 3;
                if (!$order->save()) {
                    throw new \RuntimeException('充值失败');
                }
                return true;
            }

            $plan = Plan::find($order->plan_id);
            if (!$plan) {
                throw new \RuntimeException('订阅计划不存在');
            }

            if ($order->refund_amount) {
                $this->user->balance += $order->refund_amount;
            }
            if ($order->surplus_order_ids) {
                Order::where('user_id', $order->user_id)
                    ->where('period', '!=', 'deposit')
                    ->whereIn('id', $order->surplus_order_ids)->update([
                    'status' => 4
                ]);
            }

            switch ((string)$order->period) {
                case 'onetime_price':
                    $this->buyByOneTime($order, $plan);
                    break;
                case 'reset_price':
                    $this->buyByResetTraffic();
                    break;
                default:
                    $this->buyByPeriod($order, $plan);
            }

            switch ((int)$order->type) {
                case 1:
                    $this->openEvent(config('v2board.new_order_event_id', 0));
                    break;
                case 2:
                    $this->openEvent(config('v2board.renew_order_event_id', 0));
                    break;
                case 3:
                    $this->openEvent(config('v2board.change_order_event_id', 0));
                    break;
            }

            $this->setSpeedLimit($plan->speed_limit);
            if (!$this->user->save()) {
                throw new \RuntimeException('开通失败');
            }
            $order->status = 3;
            if (!$order->save()) {
                throw new \RuntimeException('开通失败');
            }

            return true;
        }, 3);
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        // Coupon and account discounts still stack, but cannot exceed the price.
        $order->discount_amount = min($order->discount_amount, $order->total_amount);
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        if (!$user->invite_user_id) return;
        $inviter = User::find($user->invite_user_id, ['id', 'commission_type', 'commission_rate']);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user): bool
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->exists();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder) return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if ($nowUserTraffic == 0) return;
        $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if ($paidTotalAmount == 0) return;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $remainingTrafficRatio = $notUsedTraffic / $nowUserTraffic;
        $result = $remainingTrafficRatio * $paidTotalAmount;
        $order->surplus_amount = max($result, 0);
        $orderModel = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'deposit')
            ->where('status', 3);
        $order->surplus_order_ids = $orderModel->pluck('id')->all();
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'onetime_price')
            ->where('period', '!=', 'deposit')
            ->where('status', 3)
            ->get(['id', 'period', 'created_at', 'total_amount', 'balance_amount', 'surplus_amount', 'refund_amount'])
            ->toArray();
        if (!$orders) return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = null;
        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            $orderEndTime = strtotime("+{$period} month", $item['created_at']);
            if ($orderEndTime < time()) continue;
            $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
            $orderMonthSum += $period;
            $orderAmountSum += $item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount'];
        }
        if ($lastValidateAt === null) return;
    
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        $expiredAtByUser = $user->expired_at;
        if ($expiredAtByOrder < time() || $expiredAtByUser < time()) return;
        $orderSurplusSecond = $expiredAtByUser - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
    
        $totalTraffic = $user->transfer_enable;
        $usedTraffic = ($user->u + $user->d);
        if ($totalTraffic == 0) return;
    
        $remainingTrafficRatio = ($totalTraffic - $usedTraffic) / $totalTraffic;
    
        $avgPricePerSecond = $orderAmountSum / $orderRangeSecond;
        if ($orderRangeSecond <= 31 * 86400) {
            $remainingExpiredTimeRatio = $orderSurplusSecond / $orderRangeSecond;
            $surplusRatio = min($remainingExpiredTimeRatio, $remainingTrafficRatio);
            $orderSurplusAmount = $avgPricePerSecond * $orderSurplusSecond * $surplusRatio;
        } else {
            $monthSeconds = 30 * 86400;
            $firstMonthRemainSeconds = $orderSurplusSecond % $monthSeconds;
            $surplusRatio = min($firstMonthRemainSeconds / $monthSeconds, $remainingTrafficRatio);
            $laterMonthsSeconds = $orderSurplusSecond - $firstMonthRemainSeconds;
            $orderSurplusAmount = $avgPricePerSecond * $monthSeconds * $surplusRatio +
                                  $avgPricePerSecond * $laterMonthsSeconds;
        }
    
        $order->surplus_amount = max($orderSurplusAmount, 0);
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    public function updateCommissionStatus(int $status): bool
    {
        if (!in_array($status, [0, 1, 3], true)) return false;
        return DB::transaction(function () use ($status) {
            $order = Order::whereKey($this->order->id)->lockForUpdate()->first();
            if (!$order || (int)$order->commission_status === 2) return false;
            if ($status === 1 && !in_array((int)$order->status, [3, 4], true)) return false;
            if ((int)$order->commission_status === $status) return true;
            $order->commission_status = $status;
            if (!$order->save()) throw new \RuntimeException('订单佣金状态保存失败');
            $this->order = $order;
            return true;
        }, 3);
    }

    public function paid(string $callbackNo, bool $requireFree = false, ?int $paymentId = null, ?int $paidAmount = null)
    {
        $this->markedPaid = false;
        $shouldDispatch = false;
        $tradeNo = $this->order->trade_no;

        try {
            $accepted = DB::transaction(function () use ($callbackNo, $requireFree, $paymentId, $paidAmount, &$shouldDispatch, &$tradeNo) {
                $shouldDispatch = false;
                $order = Order::whereKey($this->order->id)->lockForUpdate()->first();
                if (!$order) {
                    return false;
                }
                $this->order = $order;

                if ($paymentId !== null && ($paymentId < 1 || (int) $order->payment_id !== $paymentId)) {
                    Log::warning('支付回调通道与订单不匹配，需要人工核对', [
                        'order_id' => $order->id, 'trade_no' => $order->trade_no, 'callback_no' => $callbackNo,
                        'payment_id' => $paymentId, 'expected_payment_id' => $order->payment_id,
                    ]);
                    return false;
                }
                if ($paidAmount !== null && $paidAmount !== (int) $order->total_amount + (int) $order->handling_amount) {
                    Log::warning('支付回调金额与订单不匹配，需要人工核对', [
                        'order_id' => $order->id, 'trade_no' => $order->trade_no, 'callback_no' => $callbackNo,
                        'payment_id' => $paymentId, 'paid_amount' => $paidAmount,
                        'expected_amount' => (int) $order->total_amount + (int) $order->handling_amount,
                    ]);
                    return false;
                }

                $status = (int)$order->status;
                if (in_array($status, [1, 3, 4], true)) {
                    return true;
                }
                if ($status !== 0) {
                    return false;
                }

                // Free checkout must still be free after acquiring the order lock.
                if ($requireFree && $order->total_amount > 0) {
                    return false;
                }

                $order->status = 1;
                $order->paid_at = time();
                $order->callback_no = $callbackNo;
                if (!$order->save()) {
                    throw new \RuntimeException('订单支付状态保存失败');
                }

                $this->order = $order;
                $tradeNo = $order->trade_no;
                $shouldDispatch = true;
                return true;
            }, 3);
        } catch (\Throwable $e) {
            Log::error('订单支付状态迁移失败', [
                'trade_no' => $tradeNo,
                'exception' => $e,
            ]);
            return false;
        }

        if (!$accepted) {
            return false;
        }
        if (!$shouldDispatch) {
            return true;
        }
        $this->markedPaid = true;

        try {
            OrderHandleJob::dispatch($tradeNo);
        } catch (\Throwable $e) {
            // check:order 会重新派发 status=1 的订单，不能把已收款状态回滚为待支付。
            Log::error('订单开通任务派发失败，等待定时任务恢复', [
                'trade_no' => $tradeNo,
                'exception' => $e,
            ]);
        }
        return true;
    }

    public function didMarkPaid(): bool
    {
        return $this->markedPaid;
    }

    public function cancel():bool
    {
        try {
            return DB::transaction(function () {
                $order = Order::whereKey($this->order->id)->lockForUpdate()->first();
                if (!$order) {
                    return false;
                }
                if ((int)$order->status === 2) {
                    return true;
                }
                if ((int)$order->status !== 0) {
                    return false;
                }

                if ($order->balance_amount) {
                    $user = User::whereKey($order->user_id)->lockForUpdate()->first();
                    if (!$user) {
                        throw new \RuntimeException('订单用户不存在');
                    }
                    $user->balance += (int)$order->balance_amount;
                    if (!$user->save()) {
                        throw new \RuntimeException('订单余额退回失败');
                    }
                }

                if ($order->coupon_id) {
                    $coupon = Coupon::whereKey($order->coupon_id)->lockForUpdate()->first();
                    if ($coupon && $coupon->limit_use !== null) {
                        $coupon->limit_use = (int)$coupon->limit_use + 1;
                        if (!$coupon->save()) {
                            throw new \RuntimeException('优惠券次数归还失败');
                        }
                    }
                }

                $order->status = 2;
                if (!$order->save()) {
                    throw new \RuntimeException('订单取消失败');
                }
                $this->order = $order;
                return true;
            }, 3);
        } catch (\Throwable $e) {
            Log::error('订单取消失败', [
                'trade_no' => $this->order->trade_no,
                'exception' => $e,
            ]);
                return false;
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        app(TrafficResetCheckpointService::class)->reset($this->user);
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function getbounus($total_amount) {
        return DepositBonusService::calculate((int) $total_amount);
    }
}
