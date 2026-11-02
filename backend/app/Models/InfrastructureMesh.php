<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureMesh extends Model
{
    protected $table = 'v2_infra_mesh';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'settings' => 'array', 'status' => 'array',
        'revision' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
