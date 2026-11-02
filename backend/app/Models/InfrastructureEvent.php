<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureEvent extends Model
{
    protected $table = 'v2_infra_event';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['details' => 'array', 'created_at' => 'integer'];
}
