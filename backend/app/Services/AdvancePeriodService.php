<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdvancePeriodService
{
    public function advance(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $planId = User::whereKey($id)->value('plan_id');
            // Keep plan -> user lock order consistent with subscription edits and plan deletion.
            $plan = $planId === null ? null : Plan::whereKey($planId)->sharedLock()->first(['id', 'reset_traffic_method']);
            $user = User::whereKey($id)->lockForUpdate()->first();
            if (!$user) abort(500, __('The user does not exist'));
            if ($user->banned) abort(500, __('Your account has been suspended'));
            if (($user->plan_id === null ? null : (int) $user->plan_id) !== ($planId === null ? null : (int) $planId)) {
                abort(409, '订阅已变更，请刷新后再试');
            }
            if ($user->transfer_enable > $user->u + $user->d) abort(500, __('You have not used up your traffic, you cannot renew your subscription'));
            if (!$plan) abort(500, __('You do not allow to renew the subscription'));
            $user->setRelation('plan', $plan);
            $service = new UserService();
            $resetDay = $service->getResetDay($user);
            $resetPeriod = $service->getResetPeriod($user);
            if ($resetDay === null || $resetPeriod === null) abort(500, __('You do not allow to renew the subscription'));
            switch ($resetPeriod) {
                case 1: $resetDay = 30; $resetPeriod = 30; break;
                case 12: $resetDay = 365; $resetPeriod = 365; break;
                case 30: case 365: break;
                default: abort(500, __('Invalid reset period'));
            }
            if ($resetDay <= 0) $resetDay = $resetPeriod;
            if ($user->expired_at === null || ($resetPeriod + 1) * 86400 >= $user->expired_at - time()) {
                abort(500, __('You do not have enough time to renew your subscription'));
            }
            $user->fill(['expired_at' => $user->expired_at - $resetDay * 86400]);
            app(TrafficResetCheckpointService::class)->reset($user);
            if (!$user->save() || (int) $user->getKey() !== $id) throw new \RuntimeException('Period advance save rejected');
        }, 3);
    }
}
