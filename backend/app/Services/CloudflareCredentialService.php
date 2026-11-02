<?php

namespace App\Services;

use App\Models\CloudflareCredential;
use Illuminate\Support\Facades\DB;

class CloudflareCredentialService
{
    public static function write(CloudflareCredential $snapshot, string $zoneId, callable $operation)
    {
        // Serialize Token rotation/revocation with remote writes, not just the initial read.
        return DB::transaction(function () use ($snapshot, $zoneId, $operation) {
            $credential = CloudflareCredential::query()->lockForUpdate()->findOrFail($snapshot->id);
            if ((int) $credential->revision !== (int) $snapshot->revision) abort(409, 'Cloudflare 凭据已修改，请重试');
            if (!$credential->enabled) abort(409, '此凭据尚未启用');
            if (!$credential->permitsZone($zoneId)) abort(403, '此凭据未授权当前 Zone');
            return $operation($credential);
        });
    }
}
