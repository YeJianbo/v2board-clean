<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class InfrastructureCluster extends Model
{
    protected $table = 'v2_infra_cluster';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['encrypted_secrets'];
    protected $casts = ['enabled' => 'boolean', 'sync_enabled' => 'boolean', 'failover_enabled' => 'boolean', 'split_enabled' => 'boolean',
        'settings' => 'array', 'status' => 'array', 'revision' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];

    public function primaryHost()
    {
        return $this->belongsTo(InfrastructureHost::class, 'primary_host_id');
    }

    public function standbyHost()
    {
        return $this->belongsTo(InfrastructureHost::class, 'standby_host_id');
    }

    public function credential()
    {
        return $this->belongsTo(CloudflareCredential::class, 'credential_id');
    }

    public function secrets(): array
    {
        return $this->encrypted_secrets ? json_decode(Crypt::decryptString($this->encrypted_secrets), true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
