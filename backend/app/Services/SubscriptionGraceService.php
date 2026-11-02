<?php

namespace App\Services;

use App\Exceptions\UserMutationException;
use App\Models\User;
use App\Support\TrafficReport;
use App\Utils\Helper;
use App\Support\RavelConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscriptionGraceService
{
    public const VIRTUAL_BASE = 2000000000;
    public const LIFETIME = 900;

    private function installed(): bool
    {
        return Schema::hasTable('v2_subscription_grace');
    }

    // Call only with the real user row locked inside the caller's transaction.
    public function rotate(User $user, string $mode): void
    {
        if (!in_array($mode, ['grace', 'immediate', 'revoke'], true)) abort(422, '无效的重置方式');
        if (DB::transactionLevel() < 1) throw new \LogicException('Subscription rotation requires a transaction');
        $installed = $this->installed();
        if ($mode === 'grace' && !$installed) throw new UserMutationException('订阅过渡期尚未安装', 422);
        $expiry = time() + self::LIFETIME;
        $ravelCredentials = [];
        if ($mode === 'grace') {
            if (!Schema::hasColumn('v2_subscription_grace', 'ravel_credentials')) {
                throw new UserMutationException('Ravel 订阅过渡期尚未安装', 422);
            }
            $servers = app(ServerService::class)->getAvailableServers($user, false);
            if (!$servers) throw new UserMutationException('暂无可用 Ravel 节点，请选择立即重置', 422);
            foreach ($servers as $server) {
                if (($server['type'] ?? '') !== 'v2node' || ($server['protocol'] ?? '') !== 'ravel') {
                    throw new UserMutationException('15 分钟过渡目前仅支持纯 Ravel 订阅，其他协议请选择立即重置', 422);
                }
                $nodeId = (int) ($server['parent_id'] ?? 0) ?: (int) $server['id'];
                if (!Cache::get(RavelConfig::overlapCapabilityKey($nodeId), false)) {
                    throw new UserMutationException('Ravel 节点尚未同步新版双凭据能力，请先升级节点或选择立即重置', 422);
                }
                $group = (string) $server['ravel_gateway_group'];
                if (!isset($ravelCredentials[$group])) {
                    $credentials = app(RavelCredentialService::class)->forRotation($user, $group, (array) $server['group_id']);
                    if (!$credentials) throw new UserMutationException('无法保留当前 Ravel 凭据，请选择立即重置', 422);
                    $ravelCredentials[$group] = $credentials;
                }
            }
        }
        if ($installed) {
            $previous = DB::table('v2_subscription_grace')->where('user_id', $user->id)->where('expires_at', '>', time())->value('expires_at');
            if ($previous !== null) $expiry = min($expiry, (int) $previous);
            DB::table('v2_subscription_grace')->where('user_id', $user->id)->update(['expires_at' => 0]);
        }
        if (Schema::hasColumn('v2_ravel_credential', 'renewal_credentials')) {
            // Revocation must not immediately trigger another early renewal of the current key.
            $clear = $mode === 'revoke' ? ['renewal_credentials' => null] : ['renewal_credentials' => null, 'renewal_expires_at' => 0];
            DB::table('v2_ravel_credential')->where('user_id', $user->id)->whereNotNull('renewal_credentials')
                ->update($clear);
        }
        if ($mode === 'revoke') return;
        $oldToken = (string) $user->token;
        $oldUuid = (string) $user->uuid;
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        if ($installed) {
            // The owner binding prevents delayed reports charging a deleted/recreated account.
            $ownerHash = hash('sha256', $user->token);
            DB::table('v2_subscription_grace')->where('user_id', $user->id)->update(['owner_token_hash' => $ownerHash]);
            if ($mode === 'grace') {
                DB::table('v2_subscription_grace')->insert([
                    'user_id' => $user->id, 'token_hash' => hash('sha256', $oldToken),
                    'token_ciphertext' => Crypt::encryptString($oldToken), 'uuid' => $oldUuid,
                    'owner_token_hash' => $ownerHash, 'expires_at' => $expiry, 'created_at' => time(),
                    'ravel_credentials' => Crypt::encryptString(json_encode($ravelCredentials, JSON_THROW_ON_ERROR)),
                ]);
            }
        }
    }

    public function expiresAt(int $userId): ?int
    {
        if (!$this->installed()) return null;
        $expiry = DB::table('v2_subscription_grace')->where('user_id', $userId)->where('expires_at', '>', time())->value('expires_at');
        return $expiry === null ? null : (int) $expiry;
    }

    public function resolve(string $token): ?User
    {
        if (!$this->installed()) return null;
        $row = DB::table('v2_subscription_grace')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', time())->first();
        if (!$row) return null;
        $user = User::find($row->user_id);
        if (!$user || !hash_equals($row->owner_token_hash, hash('sha256', $user->token))) return null;
        // Never map old credentials to the current subscription secret or UUID.
        $user->token = $token;
        $user->uuid = $row->uuid;
        $user->syncOriginal();
        return $user;
    }

    public function previousToken(int $userId): ?string
    {
        if (!$this->installed()) return null;
        $row = DB::table('v2_subscription_grace')->where('user_id', $userId)->where('expires_at', '>', time())->first();
        if (!$row) return null;
        $token = Crypt::decryptString($row->token_ciphertext);
        return $this->resolve($token) ? $token : null;
    }

    // Returned credentials always belong to the preceding UUID, never to the current secret.
    public function ravelCredentials(iterable $users, string $gatewayGroup, ?array $allowedGroups = null, bool $previousOnly = false): array
    {
        return array_map(static fn ($credentials) => $credentials[0], $this->ravelCredentialSets($users, $gatewayGroup, $allowedGroups, $previousOnly));
    }

    public function ravelCredentialSets(iterable $users, string $gatewayGroup, ?array $allowedGroups = null, bool $previousOnly = false): array
    {
        $users = collect($users)->keyBy('id');
        if ($users->isEmpty()) return [];
        if (!$this->installed() || !Schema::hasColumn('v2_subscription_grace', 'ravel_credentials')) return [];
        $allowedGroups = $allowedGroups === null ? null : array_fill_keys(array_map('intval', $allowedGroups), true);
        $result = [];
        foreach (array_chunk($users->keys()->all(), 500) as $ids) {
            $rows = DB::table('v2_subscription_grace')->whereIn('user_id', $ids)->where('expires_at', '>', time())
                ->whereNotNull('ravel_credentials')->get()->keyBy('user_id');
            if ($rows->isEmpty()) continue;
            $owners = User::whereIn('id', $rows->keys())->get([
                'id', 'token', 'uuid', 'group_id', 'banned', 'u', 'd', 'transfer_enable', 'expired_at',
            ])->keyBy('id');
            foreach ($rows as $row) {
                $user = $users->get($row->user_id);
                $owner = $owners->get($row->user_id);
                if (!$owner || !hash_equals($row->owner_token_hash, hash('sha256', $owner->token)) ||
                    $owner->banned || (int) $owner->u + (int) $owner->d >= (int) $owner->transfer_enable ||
                    ($owner->expired_at !== null && (int) $owner->expired_at <= time()) ||
                    ($allowedGroups !== null && !isset($allowedGroups[(int) $owner->group_id])) ||
                    ($previousOnly && (string) $user->uuid !== (string) $row->uuid) ||
                    !in_array((string) $user->uuid, [(string) $owner->uuid, (string) $row->uuid], true)) continue;
                $snapshot = json_decode(Crypt::decryptString($row->ravel_credentials), true, 512, JSON_THROW_ON_ERROR);
                $credentials = $snapshot[$gatewayGroup] ?? [];
                // Existing snapshots stored one credential per group.
                if (isset($credentials['credential_id'])) $credentials = [$credentials];
                foreach ($credentials as $credential) {
                    $credential['not_after'] = min((int) $credential['not_after'], (int) $row->expires_at,
                        $owner->expired_at === null ? PHP_INT_MAX : (int) $owner->expired_at);
                    if ($credential['not_after'] <= time() || $credential['not_after'] <= (int) $credential['not_before']) continue;
                    $result[(int) $user->id][] = $credential;
                }
            }
        }
        return $result;
    }

    public function owners(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id >= self::VIRTUAL_BASE)));
        if (!$ids) return [];
        if (!$this->installed() || User::whereIn('id', $ids)->exists()) abort(422, '虚拟身份映射不可用或发生 ID 冲突');
        $query = DB::table('v2_subscription_grace')->whereIn('id', array_map(static fn ($id) => $id - self::VIRTUAL_BASE, $ids));
        // Only pre-upgrade rows ever issued a virtual ID. New Ravel rows must not become aliases.
        if (Schema::hasColumn('v2_subscription_grace', 'ravel_credentials')) $query->whereNull('ravel_credentials');
        $rows = $query->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'token'])->keyBy('id');
        $result = [];
        foreach ($rows as $row) {
            $owner = $users->get($row->user_id);
            $result[self::VIRTUAL_BASE + (int) $row->id] = $owner && hash_equals($row->owner_token_hash, hash('sha256', $owner->token)) ? (int) $row->user_id : null;
        }
        if (count($result) !== count($ids)) abort(422, '未知的虚拟身份');
        return $result;
    }

    public function traffic(array $data): array
    {
        $owners = $this->owners(array_keys($data));
        $result = [];
        foreach ($data as $id => $traffic) {
            $target = array_key_exists($id, $owners) ? $owners[$id] : (int) $id;
            if ($target === null) continue;
            $previous = $result[$target] ?? [0, 0];
            foreach ([0, 1] as $index) {
                if ($traffic[$index] > PHP_INT_MAX - $previous[$index]) abort(422, '流量合并超出范围');
                $previous[$index] += $traffic[$index];
            }
            $result[$target] = $previous;
        }
        return TrafficReport::normalize($result);
    }

    public function online(array $data): array
    {
        $owners = $this->owners(array_keys($data));
        $result = [];
        foreach ($data as $id => $ips) {
            $target = array_key_exists($id, $owners) ? $owners[$id] : (int) $id;
            if ($target === null || $target < 1 || !is_array($ips)) continue;
            $result[$target] = array_merge($result[$target] ?? [], $ips);
        }
        return $result;
    }

}
