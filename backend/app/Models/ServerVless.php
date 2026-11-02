<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerVless extends Model
{
    use Concerns\GuardsGroupReferences;

    protected $table = 'v2_server_vless';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'rate_time_enable' => 'boolean',
        'rate_time_ranges' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'array',
        'route_id' => 'array',
        'tls_settings' => 'array',
        'network_settings' => 'array',
        'encryption_settings' => 'array',
        'tags' => 'array'
    ];
}
