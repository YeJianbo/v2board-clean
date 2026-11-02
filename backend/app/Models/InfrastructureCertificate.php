<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureCertificate extends Model
{
    protected $table = 'v2_infra_certificate';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'staging' => 'boolean', 'status' => 'array',
        'revision' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
