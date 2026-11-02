<?php

namespace App\Models\Concerns;

use App\Models\Builders\GroupReferenceBuilder;

trait GuardsGroupReferences
{
    public function newEloquentBuilder($query)
    {
        return new GroupReferenceBuilder($query);
    }
}
