<?php

namespace App\Services;

use App\Models\CloudflareCredential;
use App\Models\InfrastructureHost;
use App\Models\InfrastructureMesh;
use App\Models\InfrastructureTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InfrastructureMeshService
{
    private function client(InfrastructureMesh $group, bool $write = false): array
    {
        $credential = CloudflareCredential::findOrFail($group->credential_id);
        if (!$credential->account_id) abort(422, 'Mesh 凭据没有账户 ID');
        if ($write && (!$group->enabled || !$credential->enabled)) abort(409, 'Mesh 组或 Cloudflare 凭据尚未启用');
        return [new CloudflareApiClient($credential->token()), '/accounts/' . $credential->account_id];
    }

    public function inspect(InfrastructureMesh $group): array
    {
        [$client, $base] = $this->client($group);
        $nodes = $client->request('GET', $base . '/warp_connector', ['is_deleted' => false, 'per_page' => 1000]);
        $settings = $client->request('GET', $base . '/devices/settings')['result'] ?? [];
        $profiles = $client->request('GET', $base . '/devices/policies')['result'] ?? [];
        $profileReady = false;
        foreach ($profiles as $profile) {
            if (!($profile['enabled'] ?? true) || ($profile['tunnel_protocol'] ?? '') !== 'masque' || ($profile['service_mode_v2']['mode'] ?? '') !== 'warp') continue;
            foreach ($profile['include'] ?? [] as $route) if (($route['address'] ?? '') === '100.96.0.0/12') $profileReady = true;
        }
        return ['nodes' => $nodes['result'] ?? [], 'checks' => [
            'unique_ips' => (bool) ($settings['use_zt_virtual_ip'] ?? false),
            'tcp_proxy' => (bool) ($settings['gateway_proxy_enabled'] ?? false),
            'udp_proxy' => (bool) ($settings['gateway_udp_proxy_enabled'] ?? false),
            'masque_mesh_profile' => $profileReady,
        ], 'manual_check' => '在 Cloudflare 设备配置中确认节点注册规则、配置匹配范围和 enrolled-device reachability；本接口不会修改账户全局设置'];
    }

    private function guarded(InfrastructureMesh $snapshot, callable $operation)
    {
        $lock = Cache::lock('infra-mesh:' . $snapshot->id, 1200);
        if (!$lock->get()) abort(409, '此 Mesh 组正在处理其他操作');
        try {
            return DB::transaction(function () use ($snapshot, $operation) {
                $group = InfrastructureMesh::query()->lockForUpdate()->findOrFail($snapshot->id);
                if ((int) $snapshot->revision !== (int) $group->revision) abort(409, 'Mesh 配置已修改，请刷新');
                $credential = CloudflareCredential::query()->lockForUpdate()->findOrFail($group->credential_id);
                if (!$credential->enabled || !$group->enabled) abort(409, 'Mesh 组或 Cloudflare 凭据尚未启用');
                return $operation($group);
            });
        } finally { $lock->release(); }
    }

    public function enroll(InfrastructureMesh $group, int $hostId): array
    {
        return $this->guarded($group, function ($current) use ($hostId) {
            if (!in_array($hostId, array_map('intval', $current->settings['host_ids']), true)) abort(422, '主机不属于此 Mesh 组');
            $host = InfrastructureHost::query()->lockForUpdate()->findOrFail($hostId);
            if (!$host->allows('mesh')) abort(409, '主机 Mesh 功能尚未启用');
            if ((int) ($host->status['cpu_count'] ?? 0) < 3 || (int) $host->reported_at < time() - 300) abort(409, '需要最近五分钟内的原生 Agent 报告，且至少 3 vCPU');
            if (InfrastructureTask::where('host_id', $hostId)->whereIn('state', ['queued', 'dispatched', 'unknown'])->exists()) abort(409, '此主机仍有待处理任务');
            $inspection = $this->inspect($current);
            if (in_array(false, $inspection['checks'], true)) abort(409, '请先完成 Mesh 账户设置，当前检查尚未通过');
            [$client, $base] = $this->client($current, true);
            $name = 'panelcore-g' . $current->id . '-h' . $hostId;
            $existing = array_values(array_filter($inspection['nodes'], fn ($node) => ($node['name'] ?? '') === $name));
            if (count($existing) > 1) abort(409, '发现重名 Mesh 节点，请在 Cloudflare 检查后重试');
            $node = $existing[0] ?? ($client->request('POST', $base . '/warp_connector', ['name' => $name, 'ha' => false])['result'] ?? []);
            $id = $node['id'] ?? '';
            if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) abort(502, 'Cloudflare 没有返回有效 Mesh 节点 ID');
            $token = $client->request('GET', $base . '/warp_connector/' . $id . '/token')['result'] ?? null;
            if (!is_string($token) || strlen($token) < 32) abort(502, 'Cloudflare 没有返回有效节点 Token');
            $status = $current->status ?: [];
            $status['nodes'][(string) $hostId] = ['id' => $id, 'name' => $name, 'state' => 'enrollment_queued'];
            $current->status = $status;
            $current->save();
            $task = app(InfrastructureService::class)->enqueue($host, 'mesh.join', ['mesh_id' => (int) $current->id, 'connector_token' => $token]);
            return ['node_id' => $id, 'task' => $task, 'message' => '节点接入任务已排队，接入前会启用本机 Mesh 默认拒绝规则'];
        });
    }

    private function addresses(InfrastructureHost $host): array
    {
        if ((int) $host->reported_at < time() - 300) abort(409, 'Mesh 地址报告过期，请等待 Agent 更新');
        $ips = array_values(array_filter($host->status['mesh_ips'] ?? [], function ($ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
            $value = (int) sprintf('%u', ip2long($ip));
            return $value >= 1684013056 && $value <= 1685061631; // 100.96.0.0/12
        }));
        if (!$ips) abort(409, '主机尚未报告 Cloudflare 分配的 Mesh IPv4 地址');
        return $ips;
    }

    private function putRule(CloudflareApiClient $client, string $base, string $name, array $payload, array $existing): string
    {
        $matches = array_values(array_filter($existing, fn ($rule) => ($rule['name'] ?? '') === $name));
        if (count($matches) > 1) abort(409, '发现重名 Gateway 规则，请先检查');
        $id = $matches[0]['id'] ?? null;
        $reply = $client->request($id ? 'PUT' : 'POST', $base . '/gateway/rules' . ($id ? '/' . $id : ''), ['name' => $name] + $payload);
        $result = $reply['result'] ?? [];
        if (!preg_match('/^[a-f0-9-]{36}$/i', $result['id'] ?? '') || !($result['enabled'] ?? false) || ($result['action'] ?? '') !== $payload['action']) abort(502, 'Cloudflare 未确认 Gateway 规则');
        return $result['id'];
    }

    public function applyPolicy(InfrastructureMesh $group): array
    {
        return $this->guarded($group, function ($current) {
            [$client, $base] = $this->client($current, true);
            $otherGroups = InfrastructureMesh::where('id', '<>', $current->id)->where('enabled', true)->get();
            foreach ($otherGroups as $other) if (array_intersect($current->settings['host_ids'], $other->settings['host_ids'] ?? [])) abort(409, '同一主机不能同时属于多个已启用的 Mesh 访问组');
            $hosts = InfrastructureHost::whereIn('id', $current->settings['host_ids'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $addresses = $peers = [];
            foreach ($hosts as $host) {
                if (!$host->allows('mesh')) abort(409, '组内主机 Mesh 功能尚未启用');
                if (InfrastructureTask::where('host_id', $host->id)->whereIn('state', ['queued', 'dispatched', 'unknown'])->exists()) abort(409, '组内主机仍有待处理任务');
                $addresses[$host->id] = $this->addresses($host);
                $peers[$host->id] = [];
            }
            $guarded = $current->status['guarded_hosts'] ?? [];
            if ($hosts->contains(fn ($host) => (int) ($guarded[(string) $host->id] ?? 0) !== (int) $current->revision)) {
                $tasks = [];
                foreach ($hosts as $host) if ((int) ($guarded[(string) $host->id] ?? 0) !== (int) $current->revision) {
                    $tasks[] = app(InfrastructureService::class)->enqueue($host, 'mesh.policy', ['mesh_id' => (int) $current->id,
                        'mesh_revision' => (int) $current->revision, 'policy_phase' => 'guard', 'peers' => []]);
                }
                return ['phase' => 'guard', 'tasks' => $tasks, 'message' => '已排队收紧本机规则；全部完成后再次应用，才会发布 Cloudflare 规则并放行所选端口'];
            }
            $allow = [];
            foreach ($current->settings['allowed_pairs'] ?? [] as $pair) {
                $source = (int) $pair['source_host_id']; $destination = (int) $pair['destination_host_id'];
                if (!isset($addresses[$source], $addresses[$destination])) abort(422, '访问规则的主机不属于此组');
                $allow[] = '(net.src.ip in {' . implode(' ', $addresses[$source]) . '} and any(net.dst.ip[*] in {' . implode(' ', $addresses[$destination]) . '}) and net.dst.port in {' . implode(' ', $pair['ports']) . '})';
                foreach ($addresses[$destination] as $ip) $peers[$source][] = ['direction' => 'out', 'ip' => $ip, 'ports' => $pair['ports']];
                foreach ($addresses[$source] as $ip) $peers[$destination][] = ['direction' => 'in', 'ip' => $ip, 'ports' => $pair['ports']];
            }
            $name = 'panelcore-mesh-g' . $current->id;
            $rules = $client->request('GET', $base . '/gateway/rules')['result'] ?? [];
            $otherRuleIds = [];
            foreach ($otherGroups as $other) foreach (['allow_rule_id', 'deny_rule_id'] as $key) if (!empty($other->status['policy'][$key])) $otherRuleIds[] = $other->status['policy'][$key];
            // Other account policies must not bypass this group's deny-by-default boundary.
            foreach ($rules as $rule) if (($rule['enabled'] ?? false) && in_array('l4', $rule['filters'] ?? [], true)
                && !in_array($rule['name'] ?? '', [$name . '-allow', $name . '-deny'], true)
                && !in_array($rule['id'] ?? '', $otherRuleIds, true) && (int) ($rule['precedence'] ?? 0) <= 2) abort(409, '已有高优先级 Network 策略，请先调整其顺序再应用 Mesh 规则');
            $all = implode(' ', array_merge(...array_values($addresses)));
            $guard = '((net.src.ip in {' . $all . '} and net.offramp.type == "mesh") or (any(net.dst.ip[*] in {' . $all . '}) and net.onramp.type == "mesh"))';
            $common = ['filters' => ['l4'], 'enabled' => true, 'identity' => '', 'device_posture' => ''];
            $denyId = $this->putRule($client, $base, $name . '-deny', $common + ['action' => 'block', 'traffic' => $guard, 'precedence' => 2], $rules);
            $allowId = $this->putRule($client, $base, $name . '-allow', $common + ['action' => 'allow', 'traffic' => $allow ? '(' . implode(' or ', $allow) . ')' : 'net.dst.port == 0', 'precedence' => 1], $rules);
            $tasks = [];
            foreach ($hosts as $host) $tasks[] = app(InfrastructureService::class)->enqueue($host, 'mesh.policy', ['mesh_id' => (int) $current->id,
                'mesh_revision' => (int) $current->revision, 'policy_phase' => 'allow', 'peers' => $peers[$host->id]]);
            $status = $current->status ?: [];
            $status['policy'] = ['allow_rule_id' => $allowId, 'deny_rule_id' => $denyId, 'revision' => (int) $current->revision, 'applied_at' => time(), 'local_state' => 'queued'];
            $current->status = $status;
            $current->save();
            return ['tasks' => $tasks, 'policy' => $status['policy']];
        });
    }
}
