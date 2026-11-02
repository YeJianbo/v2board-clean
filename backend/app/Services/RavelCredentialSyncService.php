<?php

namespace App\Services;

use App\Models\ServerV2node;
use Illuminate\Support\Facades\Cache;

class RavelCredentialSyncService
{
    private const TTL = 3600;

    public function rememberPayload(int $nodeId, string $etag, array $identities): void
    {
        // Keep one sent snapshot per node; retaining every changing ETag would
        // multiply a large user manifest by the hourly polling count.
        Cache::put($this->payloadKey($nodeId), ['etag' => $etag, 'identities' => array_fill_keys($identities, true)], self::TTL);
    }

    public function acknowledge(int $nodeId, string $etag): void
    {
        $etag = trim($etag, '"');
        if (!preg_match('/\A[0-9a-f]{40}\z/D', $etag)) return;
        $snapshot = Cache::get($this->payloadKey($nodeId));
        // An authenticated node can only acknowledge a snapshot actually sent to it.
        if (is_array($snapshot) && hash_equals($snapshot['etag'], $etag)) {
            Cache::put($this->installedKey($nodeId), $snapshot['identities'], self::TTL);
        }
    }

    public function nodes(string $gatewayGroup): array
    {
        return ServerV2node::where('protocol', 'ravel')->where('ravel_gateway_group', $gatewayGroup)
            ->get(['id', 'parent_id'])->map(fn ($node) => (int) $node->parent_id ?: (int) $node->id)->unique()->values()->all();
    }

    public function supportsAcknowledgement(array $nodes): bool
    {
        if (!$nodes) return false;
        foreach ($nodes as $node) if (!is_array(Cache::get($this->installedKey($node)))) return false;
        return true;
    }

    public function installedEverywhere(string $gatewayGroup, array $credential): bool
    {
        $nodes = $this->nodes($gatewayGroup);
        if (!$nodes) return false;
        $identity = self::identity($credential);
        foreach ($nodes as $node) {
            if (!isset(Cache::get($this->installedKey($node), [])[$identity])) return false;
        }
        return true;
    }

    public static function identity(array $credential): string
    {
        return $credential['credential_id'] . ':' . $credential['key_version'];
    }

    private function payloadKey(int $nodeId): string { return 'ravel_user_snapshot:v1:' . $nodeId; }
    private function installedKey(int $nodeId): string { return 'ravel_applied_users:v1:' . $nodeId; }
}
