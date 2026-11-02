<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerV2node extends Model
{
    use Concerns\GuardsGroupReferences;

    protected static function booted(): void
    {
        static::saving(static function (self $node) {
            if ($node->parent_id || ($node->exists && !$node->isDirty(['tls_settings', 'tls', 'protocol']))) {
                return;
            }
            $original = $node->getRawOriginal('tls_settings');
            $previous = is_array($original) ? $original : (json_decode((string) $original, true) ?: []);
            $node->tls_settings = \App\Services\NodeTlsBootstrap::forNode(
                $node->tls_settings ?? [], $previous, !$node->exists,
                (string) $node->protocol, (int) $node->tls
            );
        });
    }

    protected $table = 'v2_server_v2node';
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
        'tls_settings' => 'array',
        'network_settings' => 'array',
        'encryption_settings' => 'array',
        'padding_scheme' => 'array',
        'ravel_settings' => 'array',
    ];
}
