<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudflareCredential;
use App\Models\Machine;
use App\Services\CloudflareApiClient;
use App\Services\CloudflareCredentialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CloudflareController extends Controller
{
    private const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'CAA', 'SRV', 'PTR', 'HTTPS', 'SVCB', 'LOC', 'NAPTR', 'SSHFP', 'TLSA', 'URI', 'DNSKEY', 'DS', 'CERT', 'SMIMEA', 'OPENPGPKEY'];

    private function result($data, array $extra = [])
    {
        return response(array_merge(['data' => $data], $extra))->header('Cache-Control', 'no-store, private');
    }

    public function credentials()
    {
        return $this->result(CloudflareCredential::query()->orderBy('name')->orderBy('id')->get()
            ->map(fn (CloudflareCredential $credential) => $credential->summary())->values());
    }

    public function saveCredential(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $request->merge(['name' => trim((string) $request->input('name', ''))]);
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_cloudflare_credential,id',
            'name' => ['required', 'string', 'max:100', Rule::unique('v2_cloudflare_credential', 'name')->ignore($id ?: null)],
            'api_token' => [($id ? 'nullable' : 'required'), 'string', 'max:4096', 'regex:/^[\x21-\x7e]+$/'],
            'account_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/i'],
            'allowed_zone_ids' => 'required|array|min:1|max:100',
            'allowed_zone_ids.*' => ['required', 'string', 'distinct', 'regex:/^[a-f0-9]{32}$/i'],
            'enabled' => 'sometimes|boolean',
            'revision' => $id ? 'required|integer|min:1' : 'sometimes|integer|min:1',
        ]);
        $credential = DB::transaction(function () use ($id, $params) {
            $credential = $id ? CloudflareCredential::query()->lockForUpdate()->findOrFail($id) : new CloudflareCredential();
            if ($id && (int) $credential->revision !== (int) $params['revision']) {
                abort(409, '凭据已被修改，请刷新后重试');
            }
            $credential->name = $params['name'];
            $credential->account_id = strtolower((string) ($params['account_id'] ?? '')) ?: null;
            $zones = array_values(array_unique(array_map('strtolower', $params['allowed_zone_ids'])));
            $scopeChanged = ($credential->allowed_zone_ids ?: []) !== $zones;
            $credential->allowed_zone_ids = $zones;
            if (array_key_exists('enabled', $params)) {
                $credential->enabled = (bool) $params['enabled'];
            } elseif (!$id) {
                $credential->enabled = false;
            }
            $token = trim((string) ($params['api_token'] ?? ''));
            if ($token !== '') {
                $credential->encrypted_api_token = Crypt::encryptString($token);
            }
            if ($token !== '' || $scopeChanged || !$id) {
                $credential->verification_status = 'unverified';
                $credential->verified_at = null;
                $credential->verification_error = null;
            }
            $credential->revision = $id ? (int) $credential->revision + 1 : 1;
            if (!$credential->save() || !$credential->exists || !$credential->getKey()) {
                throw new \RuntimeException('Cloudflare credential save rejected');
            }
            return $credential;
        });
        return $this->result($credential->summary());
    }

    public function dropCredential(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_cloudflare_credential,id', 'revision' => 'required|integer|min:1']);
        DB::transaction(function () use ($params) {
            $credential = CloudflareCredential::query()->lockForUpdate()->findOrFail($params['id']);
            if ((int) $credential->revision !== (int) $params['revision']) abort(409, '凭据已被修改，请刷新后重试');
            if (Machine::query()->where('ddns_credential_id', $credential->id)->exists()) {
                throw ValidationException::withMessages(['id' => '凭据仍被机器 DDNS 引用，请先解除引用']);
            }
            foreach (['v2_infra_certificate', 'v2_infra_cluster', 'v2_infra_mesh'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table) && DB::table($table)->where('credential_id', $credential->id)->exists()) {
                    throw ValidationException::withMessages(['id' => '凭据仍被证书、集群或 Mesh 引用，请先解除引用']);
                }
            }
            if (!$credential->delete()) throw new \RuntimeException('Cloudflare credential deletion rejected');
        });
        return $this->result(true);
    }

    public function verifyCredential(Request $request)
    {
        $credential = $this->credential($request);
        $revision = (int) $credential->revision;
        $error = null;
        try {
            $client = new CloudflareApiClient($credential->token());
            try {
                $token = $client->request('GET', '/user/tokens/verify');
            } catch (\RuntimeException $exception) {
                if (!$credential->account_id) throw $exception;
                $token = $client->request('GET', '/accounts/' . $credential->account_id . '/tokens/verify');
            }
            if (($token['result']['status'] ?? '') !== 'active') throw new \RuntimeException('Cloudflare Token 未激活或已过期');
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
        // A late read result must not verify a newly rotated Token or changed scope.
        $summary = DB::transaction(function () use ($credential, $revision, $error) {
            $current = CloudflareCredential::query()->lockForUpdate()->find($credential->id);
            if (!$current || (int) $current->revision !== $revision) abort(409, '凭据在验证期间已修改，请重新验证');
            $current->verification_status = $error === null ? 'valid' : 'failed';
            $current->verification_error = $error === null ? null : mb_substr($error, 0, 1000);
            $current->verified_at = now()->getTimestamp();
            if (!$current->save()) throw new \RuntimeException('Cloudflare verification save rejected');
            return $current->summary();
        });
        return $this->result($summary);
    }

    private function credential(Request $request, bool $write = false): CloudflareCredential
    {
        $params = $request->validate(['credential_id' => 'required|integer|exists:v2_cloudflare_credential,id']);
        $credential = CloudflareCredential::findOrFail($params['credential_id']);
        if ($write && !$credential->enabled) abort(409, '此凭据的 DNS 写入尚未启用');
        return $credential;
    }

    private function zone(Request $request, bool $write = false): array
    {
        $credential = $this->credential($request, $write);
        $params = $request->validate(['zone_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/i']]);
        $zoneId = strtolower($params['zone_id']);
        if (!$credential->permitsZone($zoneId)) abort(403, '此凭据未授权当前 Zone');
        return [$credential, $zoneId, new CloudflareApiClient($credential->token())];
    }

    public function zones(Request $request)
    {
        $credential = $this->credential($request);
        $params = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:10']);
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 5);
        $ids = $credential->allowed_zone_ids ?: [];
        $client = new CloudflareApiClient($credential->token());
        $zones = [];
        foreach (array_slice($ids, ($page - 1) * $perPage, $perPage) as $zoneId) {
            $payload = $client->request('GET', '/zones/' . rawurlencode($zoneId));
            $zone = $payload['result'] ?? null;
            if (!is_array($zone) || ($zone['id'] ?? '') !== $zoneId) abort(502, 'Cloudflare Zone 响应无效');
            $zones[] = ['id' => $zoneId, 'name' => $zone['name'] ?? '', 'status' => $zone['status'] ?? '', 'account' => $zone['account']['name'] ?? ''];
        }
        return $this->result($zones, ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total_count' => count($ids), 'total_pages' => (int) ceil(count($ids) / $perPage)]]);
    }

    public function records(Request $request)
    {
        [, $zoneId, $client] = $this->zone($request);
        $params = $request->validate([
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:10|max:100',
            'name' => 'sometimes|nullable|string|max:253', 'type' => ['sometimes', 'nullable', Rule::in(self::RECORD_TYPES)],
        ]);
        $query = array_filter(['page' => (int) ($params['page'] ?? 1), 'per_page' => (int) ($params['per_page'] ?? 50),
            'name' => $params['name'] ?? '', 'type' => $params['type'] ?? ''], fn ($value) => $value !== '');
        $payload = $client->request('GET', '/zones/' . rawurlencode($zoneId) . '/dns_records', $query);
        if (!is_array($payload['result'] ?? null)) abort(502, 'Cloudflare DNS 响应无效');
        return $this->result($payload['result'], ['pagination' => $payload['result_info'] ?? []]);
    }

    public function saveRecord(Request $request)
    {
        [$credential, $zoneId, $client] = $this->zone($request, true);
        $params = $request->validate([
            'record_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/i'],
            'type' => ['required', Rule::in(self::RECORD_TYPES)],
            'name' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9_.*-]+$/'],
            'content' => 'sometimes|nullable|string|max:16384', 'data' => 'sometimes|nullable|array',
            'ttl' => 'required|integer', 'proxied' => 'sometimes|boolean',
            'priority' => 'sometimes|nullable|integer|min:0|max:65535',
            'comment' => 'sometimes|nullable|string|max:500', 'tags' => 'sometimes|array|max:100', 'tags.*' => 'string|max:255',
        ]);
        $zone = $client->request('GET', '/zones/' . rawurlencode($zoneId));
        $zoneName = strtolower((string) ($zone['result']['name'] ?? ''));
        $name = strtolower(rtrim($params['name'], '.'));
        if ($zoneName === '' || ($name !== $zoneName && !str_ends_with($name, '.' . $zoneName))) {
            throw ValidationException::withMessages(['name' => '记录名称必须属于所选域名']);
        }
        if ($params['ttl'] !== 1 && ($params['ttl'] < 60 || $params['ttl'] > 86400)) {
            throw ValidationException::withMessages(['ttl' => 'TTL 须为1（自动）或60至86400秒']);
        }
        $type = $params['type'];
        if (in_array($type, ['A', 'AAAA'], true) && !filter_var($params['content'] ?? '', FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) {
            throw ValidationException::withMessages(['content' => 'IP地址与记录类型不匹配']);
        }
        if ($type === 'MX' && !isset($params['priority'])) throw ValidationException::withMessages(['priority' => 'MX记录需要优先级']);
        if (empty($params['data']) && !isset($params['content'])) throw ValidationException::withMessages(['content' => '请填写记录内容或结构化数据']);
        $recordId = strtolower((string) ($params['record_id'] ?? ''));
        unset($params['record_id']);
        $params['name'] = $name;
        $params['proxied'] = in_array($type, ['A', 'AAAA', 'CNAME'], true) && (bool) ($params['proxied'] ?? false);
        $path = '/zones/' . rawurlencode($zoneId) . '/dns_records' . ($recordId ? '/' . rawurlencode($recordId) : '');
        $record = CloudflareCredentialService::write($credential, $zoneId, function ($current) use ($recordId, $path, $params) {
            $payload = (new CloudflareApiClient($current->token()))->request($recordId ? 'PATCH' : 'POST', $path, $params);
            if (!is_array($payload['result'] ?? null) || !preg_match('/^[a-f0-9]{32}$/i', $payload['result']['id'] ?? '')) {
                abort(502, 'Cloudflare 更新响应无效，请刷新记录确认实际结果');
            }
            return $payload['result'];
        });
        return $this->result($record);
    }

    public function dropRecord(Request $request)
    {
        [$credential, $zoneId] = $this->zone($request, true);
        $params = $request->validate(['record_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/i']]);
        CloudflareCredentialService::write($credential, $zoneId, function ($current) use ($zoneId, $params) {
            (new CloudflareApiClient($current->token()))->request('DELETE', '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode(strtolower($params['record_id'])));
        });
        return $this->result(true);
    }
}
