<?php

namespace App\Services;

use App\Models\User;
use App\Support\RavelConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RavelCredentialService
{
    private const LIFETIME = 30 * 86400;
    private const RENEW_BEFORE = 86400;

    public function forUsers(iterable $users, string $gatewayGroup, ?array $allowedGroups = null): array
    {
        [$current, $renewals, $pendingSync] = $this->loadUsers($users, $gatewayGroup, $allowedGroups, true);
        foreach ($pendingSync as $id => $pending) {
            if (!isset($current[$id])) continue;
            if (!app(RavelCredentialSyncService::class)->installedEverywhere($gatewayGroup, $current[$id])) {
                // Keep subscriptions on an already installed, still valid key until every
                // gateway has applied the replacement. Merely fetching it is insufficient.
                if (!empty($renewals[$id])) $current[$id] = $renewals[$id][0];
                else unset($current[$id]);
            } else {
                DB::table('v2_ravel_credential')->where('user_id', $id)->where('gateway_group', $gatewayGroup)
                    ->where('credential_id', $current[$id]['credential_id'])->where('key_version', $current[$id]['key_version'])
                    ->update(['sync_pending' => false]);
            }
        }
        return $current;
    }

    private function loadUsers(iterable $users, string $gatewayGroup, ?array $allowedGroups, bool $includeRenewals = false): array
    {
        if (!preg_match('/\A[\x21-\x7E]{8}\z/D', $gatewayGroup)) {
            throw new RuntimeException('Invalid Ravel gateway group');
        }
        $users = collect($users);
        $requested = [];
        foreach ($users as $user) $requested[(int) $user->id] = (string) $user->uuid;
        ksort($requested);
        $allowedGroups = $allowedGroups === null ? null : array_map('intval', $allowedGroups);
        $result = [];
        $renewals = [];
        $pendingSync = [];
        foreach (array_chunk(array_keys($requested), 500) as $ids) {
            [$credentials, $pending, $history, $sync] = $this->readBatch($ids, $requested, $gatewayGroup, $allowedGroups, false, $includeRenewals);
            $result += $credentials;
            $renewals += $history;
            $pendingSync += $sync;
            if ($pending) {
                [$issued, , $history, $sync] = DB::transaction(function () use ($pending, $requested, $gatewayGroup, $allowedGroups, $includeRenewals) {
                    return $this->readBatch($pending, $requested, $gatewayGroup, $allowedGroups, true, $includeRenewals);
                }, 3);
                $result += $issued;
                $renewals += $history;
                $pendingSync += $sync;
            }
        }
        $previous = $users->reject(static fn ($user) => isset($result[(int) $user->id]));
        if ($previous->isNotEmpty()) {
            $result += app(SubscriptionGraceService::class)->ravelCredentials($previous, $gatewayGroup, $allowedGroups, true);
        }
        return [$result, $renewals, $pendingSync];
    }

    public function forNodeUsers(iterable $users, string $gatewayGroup, ?array $allowedGroups = null): array
    {
        $users = collect($users);
        [$current, $renewals] = $this->loadUsers($users, $gatewayGroup, $allowedGroups, true);
        $previous = app(SubscriptionGraceService::class)->ravelCredentialSets($users, $gatewayGroup, $allowedGroups);
        $result = [];
        foreach ($current as $id => $credential) {
            $result[$id] = $this->uniqueCredentials(array_merge([$credential], $renewals[$id] ?? [], $previous[$id] ?? []));
        }
        return $result;
    }

    public function forRotation(User $user, string $gatewayGroup, array $allowedGroups): array
    {
        [$current, $renewals] = $this->loadUsers([$user], $gatewayGroup, $allowedGroups, true);
        return isset($current[$user->id]) ? $this->uniqueCredentials(array_merge([$current[$user->id]], $renewals[$user->id] ?? [])) : [];
    }

    private function uniqueCredentials(array $credentials): array
    {
        $result = [];
        foreach ($credentials as $credential) {
            $id = $credential['credential_id'] . ':' . $credential['key_version'];
            if (!isset($result[$id])) $result[$id] = $credential;
            else $result[$id]['not_after'] = min($result[$id]['not_after'], $credential['not_after']);
        }
        return array_values($result);
    }

    private function renewalOverlapReady(string $gatewayGroup): bool
    {
        $sync = app(RavelCredentialSyncService::class);
        $nodes = $sync->nodes($gatewayGroup);
        if (!$sync->supportsAcknowledgement($nodes)) return false;
        $keys = array_map(fn ($node) => RavelConfig::overlapCapabilityKey($node), $nodes);
        foreach (Cache::many($keys) as $ready) if (!$ready) return false;
        return true;
    }

    private function readBatch(array $ids, array $requested, string $gatewayGroup, ?array $allowedGroups, bool $issue, bool $includeRenewals): array
    {
        $now = time();
        $query = User::whereIn('id', $ids)->orderBy('id');
        // Only issuance locks users; recheck their current state before creating credentials.
        if ($issue) $query->lockForUpdate();
        $current = $query->get(['id', 'uuid', 'group_id', 'expired_at', 'banned', 'u', 'd', 'transfer_enable']);
        $existing = DB::table('v2_ravel_credential')->where('gateway_group', $gatewayGroup)
            ->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $writes = [];
        $credentials = [];
        $pending = [];
        $renewals = [];
        $overlapReady = null;
        $historyInstalled = null;
        $syncInstalled = null;
        $pendingSync = [];
        foreach ($current as $user) {
            if (($allowedGroups !== null && !in_array((int) $user->group_id, $allowedGroups, true)) ||
                (string) $user->uuid !== $requested[$user->id] || $user->banned ||
                (int) $user->u + (int) $user->d >= (int) $user->transfer_enable ||
                ($user->expired_at !== null && (int) $user->expired_at <= $now)) continue;
            $row = $existing->get($user->id);
            $expires = min($now + self::LIFETIME, $user->expired_at === null ? PHP_INT_MAX : (int) $user->expired_at);
            $sameUuid = $row && $row->user_uuid === $user->uuid;
            $history = [];
            if ($sameUuid && ($includeRenewals || $issue) && !empty($row->renewal_credentials) && (int) $row->renewal_expires_at > $now) {
                $history = json_decode(Crypt::decryptString($row->renewal_credentials), true, 512, JSON_THROW_ON_ERROR);
                $history = array_values(array_filter($history, fn ($credential) => (int) $credential['not_after'] > $now));
            }
            $early = $sameUuid && (int) $row->not_after > $now && (int) $row->not_after <= $now + self::RENEW_BEFORE &&
                $expires > (int) $row->not_after && property_exists($row, 'renewal_credentials') && property_exists($row, 'sync_pending') &&
                empty($row->sync_pending) && (int) ($row->renewal_expires_at ?? 0) <= $now;
            // Finish the existing overlap before rotating again, keeping renewal history bounded.
            // Never rotate early while any node in this gateway group lacks overlap support.
            if ($early) $early = $overlapReady ?? ($overlapReady = $this->renewalOverlapReady($gatewayGroup));
            $renew = !$sameUuid || (int) $row->not_after <= $now || $early;
            if ($renew) {
                if (!$issue) {
                    $pending[] = (int) $user->id;
                    continue;
                }
                $version = $row ? (int) $row->key_version + 1 : 1;
                if ($version > 4294967295) throw new RuntimeException('Ravel credential version exhausted');
                $waitForSync = $early || ($sameUuid && !empty($row->sync_pending));
                if ($early) $history[] = $this->encodeCredential($row, Crypt::decryptString($row->capability_key), (int) $row->not_after);
                $hasHistory = $row ? property_exists($row, 'renewal_credentials') :
                    ($historyInstalled ?? ($historyInstalled = Schema::hasColumn('v2_ravel_credential', 'renewal_credentials')));
                $hasSync = $row ? property_exists($row, 'sync_pending') :
                    ($syncInstalled ?? ($syncInstalled = Schema::hasColumn('v2_ravel_credential', 'sync_pending')));
                $key = bin2hex(random_bytes(32));
                $row = (object) [
                    'user_id' => (int) $user->id, 'gateway_group' => $gatewayGroup,
                    'user_uuid' => (string) $user->uuid, 'credential_id' => bin2hex(random_bytes(16)),
                    'capability_key' => Crypt::encryptString($key), 'key_version' => $version,
                    'not_before' => max(0, $now - 60), 'not_after' => $expires,
                ];
                if ($hasHistory) {
                    $row->renewal_credentials = $history ? Crypt::encryptString(json_encode($history, JSON_THROW_ON_ERROR)) : null;
                    $row->renewal_expires_at = $history ? max(array_column($history, 'not_after')) : 0;
                }
                if ($hasSync) $row->sync_pending = $waitForSync;
                $writes[] = (array) $row;
            } else {
                $key = Crypt::decryptString($row->capability_key);
                if (!preg_match('/\A[0-9a-f]{64}\z/D', $key)) throw new RuntimeException('Invalid Ravel credential key');
            }
            // A shortened subscription takes effect without extending the original credential.
            $notAfter = min((int) $row->not_after, $expires);
            $credentials[(int) $user->id] = $this->encodeCredential($row, $key, $notAfter);
            if (!empty($row->sync_pending)) $pendingSync[(int) $user->id] = true;
            if ($includeRenewals && $history) {
                foreach ($history as &$credential) $credential['not_after'] = min((int) $credential['not_after'], $expires);
                unset($credential);
                $renewals[(int) $user->id] = $history;
            }
        }
        if ($writes) {
            $columns = ['user_uuid', 'credential_id', 'capability_key', 'key_version', 'not_before', 'not_after'];
            if (array_key_exists('renewal_credentials', $writes[0])) $columns = array_merge($columns, ['renewal_credentials', 'renewal_expires_at']);
            if (array_key_exists('sync_pending', $writes[0])) $columns[] = 'sync_pending';
            DB::table('v2_ravel_credential')->upsert($writes, ['user_id', 'gateway_group'], $columns);
        }
        return [$credentials, $pending, $renewals, $pendingSync];
    }

    private function encodeCredential(object $row, string $key, int $notAfter): array
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $key)) throw new RuntimeException('Invalid Ravel credential key');
        return ['credential_id' => $row->credential_id, 'capability_key' => $key,
            'key_version' => (int) $row->key_version, 'policy_id' => 0, 'gateway_group' => $row->gateway_group,
            'not_before' => (int) $row->not_before, 'not_after' => $notAfter];
    }
}
