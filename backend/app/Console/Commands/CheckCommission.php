<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->autoCheck();
        return $this->autoPayCommission() > 0 ? 1 : 0;
    }

    public function autoCheck()
    {
        if ((int)config('v2board.commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->whereIn('status', [3, 4])
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        $failures = 0;
        $orders = Order::select('id')->where('commission_status', 1)
            ->whereIn('status', [3, 4])
            ->where('invite_user_id', '!=', NULL)
            ->lazyById(200);
        foreach ($orders as $candidate) {
            try {
                DB::transaction(function () use ($candidate) {
                    $order = Order::whereKey($candidate->id)->lockForUpdate()->first();
                    if (!$order || (int)$order->commission_status !== 1 || !$order->invite_user_id
                        || !in_array((int)$order->status, [3, 4], true)) return;
                    if (!$this->payHandle($order->invite_user_id, $order)) throw new \RuntimeException('Commission payment failed');
                    $order->commission_status = 2;
                    if (!$order->save()) throw new \RuntimeException('Commission order update failed');
                }, 3);
            } catch (\Throwable $error) {
                $failures++;
                Log::error('Commission payment rolled back', ['order_id' => $candidate->id, 'error_type' => get_class($error), 'exception' => $error]);
            }
        }
        return $failures;
    }

    public function payHandle($inviteUserId, Order $order)
    {
        if ((float)$order->commission_balance <= 0) return true;
        if ((int)config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        $level = count($commissionShareLevels);
        $visited = [(int)$order->user_id => true];
        for ($l = 0; $l < $level; $l++) {
            if (!$inviteUserId || isset($visited[(int)$inviteUserId])) break;
            $inviter = User::whereKey($inviteUserId)->lockForUpdate()->first(['id', 'invite_user_id', 'balance', 'commission_balance']);
            if (!$inviter) break;
            $visited[(int)$inviter->id] = true;
            $inviteUserId = $inviter->invite_user_id;
            $commissionBalance = $order->commission_balance * (($commissionShareLevels[$l] ?? 0) / 100);
            if ($commissionBalance <= 0) continue;
            if ((int)config('v2board.withdraw_close_enable', 0)) {
                $inviter->balance = $inviter->balance + $commissionBalance;
            } else {
                $inviter->commission_balance = $inviter->commission_balance + $commissionBalance;
            }
            if (!$inviter->save()) {
                throw new \RuntimeException('Commission balance update failed');
            }
            $log = new CommissionLog([
                'invite_user_id' => $inviter->id,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ]);
            if (!$log->save()) throw new \RuntimeException('Commission log insert failed');
            // update order actual commission balance
            $order->actual_commission_balance = $order->actual_commission_balance + $commissionBalance;
        }
        return true;
    }

}
