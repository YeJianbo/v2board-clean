<?php

namespace App\Services;

use App\Models\CloudflareCredential;
use App\Models\InfrastructureBackup;
use App\Models\InfrastructureCertificate;
use App\Models\InfrastructureCluster;
use App\Models\InfrastructureEvent;
use App\Models\InfrastructureHost;
use App\Models\InfrastructureMesh;
use App\Models\InfrastructureTask;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InfrastructureService
{
    public const SERVICES = ['nginx', 'php-fpm', 'database', 'redis', 'horizon', 'scheduler', 'certbot', 'mesh'];
    public const ACTION_FEATURES = [
        'service.status' => 'services', 'service.logs' => 'services', 'service.inspect' => 'services', 'service.restart' => 'services',
        'service.start' => 'services', 'service.stop' => 'services', 'schedule.save' => 'services',
        'backup.create' => 'backups', 'backup.restore' => 'restores', 'backup.deploy' => 'deploy',
        'certificate.issue' => 'certificates', 'certificate.renew' => 'certificates', 'certificate.check' => 'certificates',
        'cluster.configure' => 'replication', 'cluster.sync' => 'replication', 'cluster.failover' => 'replication',
        'cluster.split' => 'replication', 'mesh.join' => 'mesh', 'mesh.status' => 'mesh', 'mesh.disconnect' => 'mesh', 'mesh.policy' => 'mesh',
    ];

    public function event(string $kind, string $message, string $level = 'info', ?int $hostId = null, ?int $clusterId = null, array $details = []): void
    {
        InfrastructureEvent::create(['kind' => $kind, 'message' => mb_substr($message, 0, 2000), 'level' => $level,
            'host_id' => $hostId, 'cluster_id' => $clusterId, 'details' => $details ?: null, 'created_at' => time()]);
    }

    public function enqueue(InfrastructureHost $host, string $action, array $payload = []): InfrastructureTask
    {
        return DB::transaction(function () use ($host, $action, $payload) {
            $current = InfrastructureHost::query()->lockForUpdate()->findOrFail($host->id);
            $feature = self::ACTION_FEATURES[$action] ?? null;
            if (!$feature) abort(422, '不支持此基础设施操作');
            if (!$current->allows($feature)) abort(409, '主机或对应功能尚未启用');
            // An uncertain destructive result must be resolved before dispatching another task.
            if (InfrastructureTask::where('host_id', $current->id)->whereIn('state', ['queued', 'dispatched', 'unknown'])->exists()) {
                abort(409, '此主机仍有待处理或结果不明的任务，请先查看结果');
            }
            $task = new InfrastructureTask();
            $task->id = (string) Str::uuid();
            $task->host_id = $current->id;
            $task->action = $action;
            $task->state = 'queued';
            $task->encrypted_payload = Crypt::encryptString(json_encode($payload + ['host_revision' => (int) $current->revision], JSON_THROW_ON_ERROR));
            $task->expires_at = time() + 86400;
            $task->save();
            $this->event('task.queued', $action . ' 已排队', 'info', $current->id, $payload['cluster_id'] ?? null, ['task_id' => $task->id]);
            return $task;
        });
    }

    public function credential(int $id, ?string $zoneId = null, bool $lock = false): CloudflareCredential
    {
        $credential = CloudflareCredential::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        if (!$credential->enabled) abort(409, 'Cloudflare 凭据尚未启用');
        if ($zoneId !== null && !$credential->permitsZone($zoneId)) abort(403, 'Cloudflare 凭据未授权此 Zone');
        return $credential;
    }

    public function certificatePayload(InfrastructureCertificate $certificate, ?int $expectedCredentialRevision = null): array
    {
        if (!$certificate->enabled) abort(409, '证书管理尚未启用');
        $credential = $this->credential((int) $certificate->credential_id, $certificate->zone_id);
        if ($expectedCredentialRevision !== null && (int) $credential->revision !== $expectedCredentialRevision) abort(409, '证书凭据已修改');
        $token = $credential->token();
        $client = new CloudflareApiClient($token);
        $zone = $client->request('GET', '/zones/' . $certificate->zone_id);
        $zoneName = strtolower((string) ($zone['result']['name'] ?? ''));
        if (!$zoneName || ($certificate->domain !== $zoneName && !str_ends_with($certificate->domain, '.' . $zoneName))) {
            abort(422, '证书域名不属于所选 Zone');
        }
        return ['certificate_id' => (int) $certificate->id, 'certificate_revision' => (int) $certificate->revision,
            'domain' => $certificate->domain, 'email' => $certificate->email,
            'staging' => $certificate->staging, 'cloudflare_token' => $token, 'credential_id' => (int) $credential->id,
            'credential_revision' => (int) $credential->revision, 'zone_id' => $certificate->zone_id];
    }

    public function clusterPayload(InfrastructureCluster $cluster, InfrastructureHost $host, bool $strictDns = true): array
    {
        if (!$cluster->enabled) abort(409, '主备集群尚未启用');
        // Polling can reuse request-local relations; task dispatch always reads current authority.
        $primary = !$strictDns && $cluster->relationLoaded('primaryHost') ? $cluster->primaryHost : InfrastructureHost::findOrFail($cluster->primary_host_id);
        $standby = !$strictDns && $cluster->relationLoaded('standbyHost') ? $cluster->standbyHost : InfrastructureHost::findOrFail($cluster->standby_host_id);
        if (!$primary || !$standby) abort(404, '集群主机不存在');
        if (!in_array((int) $host->id, [(int) $primary->id, (int) $standby->id], true)) abort(422, '主机不属于此集群');
        $settings = $cluster->settings ?: [];
        $settings['primary_site_root'] = $primary->settings['site_root'];
        $settings['standby_site_root'] = $standby->settings['site_root'];
        $secrets = $cluster->secrets();
        $usesDns = $cluster->failover_enabled || ($cluster->split_enabled && !empty($settings['dns_records']));
        $credential = null;
        if ($usesDns && $cluster->credential_id) {
            if ($strictDns) $credential = $this->credential((int) $cluster->credential_id, $settings['zone_id'] ?? null, DB::transactionLevel() > 0);
            else {
                $candidate = $cluster->relationLoaded('credential') ? $cluster->credential : CloudflareCredential::find($cluster->credential_id);
                if ($candidate && $candidate->enabled && $candidate->permitsZone($settings['zone_id'] ?? '')) $credential = $candidate;
            }
        }
        $role = (int) $host->id === (int) $primary->id ? 'primary' : 'standby';
        $roleSecrets = $secrets[$role] ?? [];
        $failoverReady = $credential && !empty($settings['arbiter_url']) && !empty($roleSecrets['node_token']);
        if ($strictDns && $cluster->failover_enabled && !$failoverReady) {
            abort(422, '自动故障转移需要 Cloudflare 凭据、仲裁地址和此节点的仲裁凭据');
        }
        return ['cluster_id' => (int) $cluster->id, 'cluster_revision' => (int) $cluster->revision, 'role' => $role,
            'primary_machine_id' => (int) $primary->machine_id, 'standby_machine_id' => (int) $standby->machine_id,
            'sync_enabled' => (bool) $cluster->sync_enabled, 'failover_enabled' => $cluster->failover_enabled && $failoverReady,
            'split_enabled' => $cluster->split_enabled && (!$usesDns || $credential), 'settings' => $settings,
            'secrets' => $roleSecrets + array_intersect_key($secrets, array_flip(['replication_password', 'notify_url'])),
            'credential_id' => $credential ? (int) $credential->id : null,
            'credential_revision' => $credential ? (int) $credential->revision : null,
            'cloudflare_token' => $credential ? $credential->token() : null,
            'dns_authorized_until' => $credential ? time() + 3600 : 0,
            'dns_state' => $usesDns && !$credential ? 'unavailable' : 'ready'];
    }

    public function pull(InfrastructureHost $host): ?array
    {
        if (!$host->enabled) return null;
        $candidate = InfrastructureTask::where('host_id', $host->id)->whereIn('state', ['queued', 'dispatched'])
            ->where('action', '<>', 'migration.ssh')->orderBy('created_at')->first();
        if (!$candidate) return null;

        $certificatePayload = null;
        $invalidCertificate = false;
        if (str_starts_with($candidate->action, 'certificate.') && $candidate->expires_at > time() && $host->allows('certificates')) {
            try {
                $stored = $candidate->payload();
                $certificate = InfrastructureCertificate::where('host_id', $host->id)->findOrFail($stored['certificate_id']);
                if (!$this->certificateMatches($certificate, $stored)) abort(409, '证书配置已修改');
                // Cloudflare may take seconds; never hold the host/task locks while waiting.
                $certificatePayload = $this->certificatePayload($certificate, isset($stored['credential_revision']) ? (int) $stored['credential_revision'] : null);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface | \Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && !in_array($exception->getStatusCode(), [403, 404, 409, 422], true)) throw $exception;
                $invalidCertificate = true;
            }
        }

        return DB::transaction(function () use ($host, $candidate, $certificatePayload, $invalidCertificate) {
            $current = InfrastructureHost::query()->lockForUpdate()->findOrFail($host->id);
            if (!$current->enabled) return null;
            $task = InfrastructureTask::where('host_id', $host->id)->whereIn('state', ['queued', 'dispatched'])
                ->whereKey($candidate->id)->lockForUpdate()->first();
            if (!$task) return null;
            if ($task->encrypted_payload !== $candidate->encrypted_payload || $task->action !== $candidate->action) return null;
            if ($task->expires_at <= time()) {
                $task->state = $task->state === 'queued' ? 'expired' : 'unknown';
                $task->completed_at = time();
                $task->save();
                return null;
            }
            $feature = self::ACTION_FEATURES[$task->action] ?? '';
            if (!$current->allows($feature)) return null;
            $payload = $task->payload();
            if ($task->state === 'queued' && (int) ($payload['host_revision'] ?? 0) !== (int) $current->revision) {
                $task->state = 'cancelled';
                $task->result = ['message' => '主机配置已修改，任务未派发'];
                $task->completed_at = time();
                $task->save();
                return null;
            }
            try {
                if (str_starts_with($task->action, 'certificate.')) {
                    if ($invalidCertificate) abort(409, '证书配置已失效');
                    if (!$certificatePayload) return null;
                    $certificate = InfrastructureCertificate::where('host_id', $host->id)->lockForUpdate()->findOrFail($payload['certificate_id']);
                    if (!$certificate->enabled || !$this->certificateMatches($certificate, $payload)) abort(409, '证书配置已修改');
                    $credential = CloudflareCredential::query()->lockForUpdate()->findOrFail($certificate->credential_id);
                    if (!$credential->enabled || !$credential->permitsZone($certificate->zone_id)) abort(403, '证书凭据已撤销');
                    if (isset($payload['credential_revision']) && (int) $credential->revision !== (int) $payload['credential_revision']) abort(409, '证书凭据已修改');
                    if ((int) $certificate->revision !== $certificatePayload['certificate_revision']
                        || (int) $credential->revision !== $certificatePayload['credential_revision']) return null;
                    $payload = $certificatePayload + $payload;
                }
                if (str_starts_with($task->action, 'cluster.')) {
                    $cluster = InfrastructureCluster::query()->lockForUpdate()->findOrFail($payload['cluster_id']);
                    $clusterPayload = $this->clusterPayload($cluster, $current);
                    if (!$this->clusterMatches($payload, $clusterPayload)) abort(409, '集群配置或凭据已修改');
                    $payload = $clusterPayload + $payload;
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface | \Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && !in_array($exception->getStatusCode(), [403, 404, 409, 422], true)) throw $exception;
                $task->state = $task->state === 'queued' ? 'cancelled' : 'unknown';
                $task->result = ['message' => '操作配置已停用、撤销或失效，已停止派发'];
                $task->completed_at = time();
                $task->save();
                return null;
            }
            if ($task->state === 'queued') {
                $task->state = 'dispatched';
                $task->dispatched_at = time();
                $task->save();
            }
            return ['id' => $task->id, 'action' => $task->action, 'expires_at' => (int) $task->expires_at,
                'payload' => $payload, 'host' => ['site_root' => $current->settings['site_root'] ?? '', 'domain' => $current->settings['domain'] ?? '']];
        });
    }

    private function certificateMatches(InfrastructureCertificate $certificate, array $payload): bool
    {
        if (isset($payload['certificate_revision']) && (int) $payload['certificate_revision'] !== (int) $certificate->revision) return false;
        foreach (['domain', 'email', 'zone_id', 'credential_id', 'staging'] as $field) {
            if (!array_key_exists($field, $payload) || (string) $certificate->$field !== (string) $payload[$field]) return false;
        }
        return true;
    }

    private function clusterMatches(array $stored, array $current): bool
    {
        // Lease expiry is renewable; confirmed targets, settings and secrets are not.
        foreach (['cluster_revision', 'role', 'primary_machine_id', 'standby_machine_id', 'sync_enabled',
            'failover_enabled', 'split_enabled', 'settings', 'secrets', 'cloudflare_token'] as $field) {
            if (!array_key_exists($field, $stored) || $stored[$field] !== $current[$field]) return false;
        }
        foreach (['credential_id', 'credential_revision'] as $field) {
            if (array_key_exists($field, $stored) && $stored[$field] !== $current[$field]) return false;
        }
        return true;
    }

    public function acknowledge(InfrastructureHost $host, InfrastructureTask $task, string $state, array $result): void
    {
        $payload = $task->payload();
        $result = $this->redactResult($result, $payload);
        $changed = DB::transaction(function () use ($host, $task, $state, $result, $payload) {
            InfrastructureHost::query()->lockForUpdate()->findOrFail($host->id);
            $current = InfrastructureTask::query()->lockForUpdate()->findOrFail($task->id);
            if ((int) $current->host_id !== (int) $host->id) abort(403);
            if (!in_array($current->state, ['dispatched', 'unknown'], true)) {
                if ($current->state === $state) return false;
                abort(409, '任务已结束或尚未派发');
            }
            $current->state = $state;
            $current->result = $result;
            $current->completed_at = time();
            $current->save();
            if (isset($payload['certificate_id'])) {
                $certificate = InfrastructureCertificate::whereKey($payload['certificate_id'])->where('host_id', $host->id)->lockForUpdate()->first();
                if ($certificate && $this->certificateMatches($certificate, $payload)) {
                    $certificate->update(['status' => $result + ['state' => $state, 'reported_at' => time()]]);
                }
            }
            if (isset($payload['cluster_id'])) {
                $cluster = InfrastructureCluster::query()->lockForUpdate()->find($payload['cluster_id']);
                if ($cluster && (int) $cluster->revision === (int) ($payload['cluster_revision'] ?? 0)
                    && in_array((int) $host->id, [(int) $cluster->primary_host_id, (int) $cluster->standby_host_id], true)
                    && ($payload['role'] ?? null) === ((int) $host->id === (int) $cluster->primary_host_id ? 'primary' : 'standby')) {
                    $role = (int) $host->id === (int) $cluster->primary_host_id ? 'primary' : 'standby';
                    $status = $cluster->status ?: [];
                    $status[$role] = $result + ['state' => $state, 'reported_at' => time()];
                    $cluster->status = $status;
                    $cluster->save();
                }
            }
            if (isset($payload['mesh_id'])) {
                $mesh = InfrastructureMesh::query()->lockForUpdate()->find($payload['mesh_id']);
                if ($mesh && (int) ($payload['mesh_revision'] ?? 0) === (int) $mesh->revision) {
                    $status = $mesh->status ?: [];
                    if ($current->action === 'mesh.policy' && $state === 'success' && ($payload['policy_phase'] ?? '') === 'guard') {
                        $status['guarded_hosts'][(string) $host->id] = (int) $mesh->revision;
                    }
                    $status['hosts'][(string) $host->id] = ['state' => $state, 'action' => $current->action, 'phase' => $payload['policy_phase'] ?? null, 'reported_at' => time()];
                    $mesh->status = $status;
                    $mesh->save();
                }
            }
            $this->event('task.' . $state, $current->action . ': ' . ($result['message'] ?? $state), $state === 'success' ? 'info' : 'error',
                $host->id, $payload['cluster_id'] ?? null, ['task_id' => $task->id]);
            return true;
        });
        if ($changed && $state !== 'success' && str_starts_with($task->action, 'certificate.')) {
            try {
                app(TelegramService::class)->sendMessageWithAdmin(implode("\n", ['证书管理失败', '主机：' . $host->name,
                    '操作：' . $task->action, '结果：' . ($result['message'] ?? $state)]), false, '');
            } catch (\Throwable $exception) {
                $this->event('notification.failed', '证书失败通知未能入队', 'error', $host->id);
            }
        }
    }

    public function redactResult(array $result, array $payload): array
    {
        $secrets = [];
        $collect = function ($value, $key = '') use (&$collect, &$secrets) {
            if (is_array($value)) foreach ($value as $nestedKey => $nested) $collect($nested, (string) $nestedKey);
            elseif (is_string($value) && $value !== '' && preg_match('/token|password|secret|notify_url/i', $key)) $secrets[] = $value;
        };
        $collect($payload);
        $raw = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ($secrets as $secret) {
            $encoded = trim(json_encode($secret, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '"');
            $raw = str_replace([$secret, $encoded], '[redacted]', $raw);
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
