<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlanService
{
    public $plan;

    public function __construct(int $planId)
    {
        $this->plan = Plan::find($planId);
    }

    public function haveCapacity(): bool
    {
        if ($this->plan->capacity_limit === NULL) return true;
        $count = User::where('plan_id', $this->plan->id)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhereNull('expired_at');
            })
            ->count();
        return ($this->plan->capacity_limit - $count) > 0;
    }

    public static function countActiveUsers(?array $planIds = null)
    {
        if ($planIds === []) return collect();
        return User::select(
            DB::raw("plan_id"),
            DB::raw("count(*) as count")
        )
            ->when($planIds !== null, fn ($query) => $query->whereIn('plan_id', $planIds))
            ->where('plan_id', '!=', NULL)
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->groupBy("plan_id")
            ->get()
            ->keyBy('plan_id');
    }
}
