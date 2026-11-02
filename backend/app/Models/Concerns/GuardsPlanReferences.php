<?php

namespace App\Models\Concerns;

use App\Models\Builders\PlanReferenceBuilder;

trait GuardsPlanReferences
{
    public function newEloquentBuilder($query)
    {
        return new PlanReferenceBuilder($query);
    }
}
