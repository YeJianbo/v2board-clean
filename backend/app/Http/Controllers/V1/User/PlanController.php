<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate(['id' => 'nullable|integer|min:0|max:2147483647']);
        $user = User::find($request->user['id'], ['id', 'plan_id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                abort(500, __('Subscription plan does not exist'));
            }
            if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
                abort(500, __('Subscription plan does not exist'));
            }
            return response([
                'data' => $plan
            ]);
        }

        $plans = Plan::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get();
        $limitedPlanIds = $plans->filter(fn ($plan) => $plan->capacity_limit !== null)->pluck('id')->all();
        $counts = PlanService::countActiveUsers($limitedPlanIds);
        foreach ($plans as $k => $v) {
            if ($plans[$k]->capacity_limit === NULL) continue;
            if (!isset($counts[$plans[$k]->id])) continue;
            $plans[$k]->capacity_limit = $plans[$k]->capacity_limit - $counts[$plans[$k]->id]->count;
        }
        return response([
            'data' => $plans
        ]);
    }
}
