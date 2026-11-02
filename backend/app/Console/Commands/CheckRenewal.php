<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Order;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckRenewal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动续费';

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
        $now = time();
        $failed = 0;
        User::query()
            ->select('id')
            ->where('auto_renewal', 1)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<', $now + 86400 * 2)
            ->chunkById(100, function ($users) use (&$failed) {
                foreach ($users as $candidate) {
                    try {
                        DB::transaction(function () use ($candidate) {
                            $user = User::whereKey($candidate->id)->lockForUpdate()
                                ->first(['id', 'auto_renewal', 'plan_id', 'expired_at', 'balance']);
                            if (!$user || !$user->auto_renewal || !$user->plan_id || !$user->expired_at) {
                                return;
                            }
                            $currentTime = time();
                            if ($user->expired_at <= $currentTime || $user->expired_at >= $currentTime + 86400 * 2) {
                                return;
                            }

                            $latestOrder = Order::where('user_id', $user->id)
                                ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
                                ->where('status', 3)
                                ->orderByDesc('created_at')
                                ->orderByDesc('id')
                                ->first(['period']);
                            if (!$latestOrder) {
                                $this->disableRenewal($user);
                                return;
                            }

                            $latestPeriod = $latestOrder->period;
                            $expiresAt = $this->getTime($latestPeriod, $user->expired_at);
                            if (!$expiresAt || $expiresAt <= $user->expired_at) {
                                $this->disableRenewal($user);
                                return;
                            }
                            $plan = Plan::find($user->plan_id, ['id', 'renew', $latestPeriod]);
                            if (!$plan || !$plan->renew) {
                                $this->disableRenewal($user);
                                return;
                            }
                            $price = filter_var($plan[$latestPeriod], FILTER_VALIDATE_INT);
                            if ($price === false || $price < 0 || $user->balance < $price) {
                                $this->disableRenewal($user);
                                return;
                            }

                            $order = new Order();
                            $order->user_id = $user->id;
                            $order->plan_id = $plan->id;
                            $order->period = $latestPeriod;
                            $order->trade_no = Helper::generateOrderNo();
                            $order->balance_amount = $price;
                            $order->total_amount = 0;
                            $order->type = 2;
                            $order->status = 3;

                            $user->balance -= $price;
                            $user->expired_at = $expiresAt;
                            if (!$user->save() || !$order->save()) {
                                throw new \RuntimeException('Automatic renewal could not be saved');
                            }
                        }, 3);
                    } catch (\Throwable $error) {
                        // Infrastructure failures must not silently opt users out of renewal.
                        $failed++;
                        Log::warning('Automatic renewal failed', [
                            'user_id' => (int) $candidate->id, 'exception' => $error,
                        ]);
                    }
                }
            });
        return $failed ? 1 : 0;
    }

    private function disableRenewal(User $user): void
    {
        $user->auto_renewal = 0;
        if (!$user->save()) throw new \RuntimeException('Automatic renewal preference could not be saved');
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
}
