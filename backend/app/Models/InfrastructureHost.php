<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfrastructureHost extends Model
{
    public const FEATURES = ['services', 'backups', 'restores', 'deploy', 'certificates', 'replication', 'mesh'];
    protected $table = 'v2_infra_host';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'features' => 'array', 'settings' => 'array', 'status' => 'array',
        'reported_at' => 'integer', 'revision' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];

    public function allows(string $feature): bool
    {
        return $this->enabled && (bool) (($this->features ?: [])[$feature] ?? false);
    }
}
