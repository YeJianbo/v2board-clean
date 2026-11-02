<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerShadowsocks extends Model
{
    use Concerns\GuardsGroupReferences;

    protected $table = 'v2_server_shadowsocks';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'rate_time_enable' => 'boolean',
        'rate_time_ranges' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'array',
        'route_id' => 'array',
        'tags' => 'array',
        'obfs_settings' => 'array'
    ];
}
