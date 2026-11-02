<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class CloudflareCredential extends Model
{
    protected $table = 'v2_cloudflare_credential';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['encrypted_api_token'];
    protected $casts = [
        'allowed_zone_ids' => 'array',
        'enabled' => 'boolean',
        'revision' => 'integer',
        'verified_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function token(): string
    {
        try {
            return Crypt::decryptString((string) $this->encrypted_api_token);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Cloudflare 凭据无法解密，请重新保存 Token');
        }
    }

    public function permitsZone(string $zoneId): bool
    {
        return in_array($zoneId, $this->allowed_zone_ids ?: [], true);
    }

    public function summary(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'account_id' => $this->account_id,
            'allowed_zone_ids' => $this->allowed_zone_ids ?: [],
            'enabled' => (bool) $this->enabled,
            'has_token' => (string) $this->encrypted_api_token !== '',
            'revision' => (int) $this->revision,
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at,
            'verification_error' => $this->verification_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
