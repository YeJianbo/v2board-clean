<?php

namespace App\Services;

use App\Models\CloudflareCredential;
use App\Models\InfrastructureBackup;
use App\Models\InfrastructureCertificate;
use App\Models\InfrastructureCluster;
use App\Models\InfrastructureEvent;
use App\Models\InfrastructureHost;
use App\Models\InfrastructureTask;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class InfrastructureAgentService
{
    private function host(Machine $machine): InfrastructureHost
    {
        return InfrastructureHost::where('machine_id', $machine->id)->firstOrFail();
    }

    public function poll(Machine $machine, Request $request)
    {
        $host = $this->host($machine);
        $params = $request->validate(['status' => 'sometimes|array', 'status.agent_version' => 'sometimes|string|max:30',
            'status.services' => 'sometimes|array', 'status.mesh_ips' => 'sometimes|array|max:20', 'status.mesh_ips.*' => 'ip',
            'status.database' => 'sometimes|array', 'status.cpu_count' => 'sometimes|integer|min:1|max:4096', 'status.certificates' => 'sometimes|array|max:100',
            'status.capabilities' => 'sometimes|array', 'status.scheduler_enabled' => 'sometimes|boolean', 'status.site_root' => 'sometimes|string|max:200']);
        $status = $params['status'] ?? [];
        $status = array_intersect_key($status, array_flip(['agent_version', 'services', 'mesh_ips', 'database', 'cpu_count', 'certificates', 'capabilities', 'scheduler_enabled', 'site_root']));
        if (strlen(json_encode($status, JSON_THROW_ON_ERROR)) > 32768) abort(413, '状态报告过大');
        if ($status) {
            $host->status = $status;
            $host->reported_at = time();
            $host->save();
        }
        $certificates = InfrastructureCertificate::where('host_id', $host->id)->get();
        $credentialMap = CloudflareCredential::whereIn('id', $certificates->pluck('credential_id')->unique())->get()->keyBy('id');
        $certificates = $certificates->map(function ($certificate) use ($host, $credentialMap) {
            $credential = $credentialMap->get($certificate->credential_id);
            $enabled = $host->allows('certificates') && $certificate->enabled && $credential && $credential->enabled && $credential->permitsZone($certificate->zone_id);
            $entry = ['id' => (int) $certificate->id, 'domain' => $certificate->domain, 'staging' => $certificate->staging,
                'enabled' => (bool) $enabled, 'credential_id' => (int) $certificate->credential_id, 'zone_id' => $certificate->zone_id];
            if ($enabled) $entry['cloudflare_token'] = $credential->token();
            return $entry;
        });
        $clusters = InfrastructureCluster::where(function ($query) use ($host) {
            $query->where('primary_host_id', $host->id)->orWhere('standby_host_id', $host->id);
        })->get();
        if ($host->allows('replication')) {
            $clusters->filter(fn ($cluster) => $cluster->enabled)->load(['primaryHost', 'standbyHost', 'credential']);
        }
        $clusters = $clusters->map(function ($cluster) use ($host) {
            $enabled = $host->allows('replication') && $cluster->enabled;
            $entry = ['cluster_id' => (int) $cluster->id, 'cluster_revision' => (int) $cluster->revision,
                'role' => (int) $cluster->primary_host_id === (int) $host->id ? 'primary' : 'standby', 'managed_enabled' => (bool) $enabled,
                'sync_enabled' => (bool) $cluster->sync_enabled, 'failover_enabled' => (bool) $cluster->failover_enabled,
                'split_enabled' => (bool) $cluster->split_enabled, 'settings' => $cluster->settings];
            if ($enabled) $entry = app(InfrastructureService::class)->clusterPayload($cluster, $host, false) + $entry;
            return $entry;
        });
        return response()->json(['data' => ['enabled' => $host->enabled, 'features' => $host->features, 'certificates' => $certificates,
            'clusters' => $clusters,
            'task' => app(InfrastructureService::class)->pull($host)]])->header('Cache-Control', 'no-store');
    }

    public function acknowledge(Machine $machine, Request $request)
    {
        $params = $request->validate(['task_id' => 'required|uuid|exists:v2_infra_task,id', 'state' => 'required|in:success,failed,unknown',
            'result' => 'required|array', 'result.message' => 'required|string|max:2000', 'result.exit_code' => 'sometimes|integer',
            'result.logs' => 'sometimes|string|max:32768']);
        if (strlen(json_encode($params['result'], JSON_THROW_ON_ERROR)) > 65536) abort(413, '任务结果过大');
        $host = $this->host($machine);
        $task = InfrastructureTask::where('host_id', $host->id)->findOrFail($params['task_id']);
        app(InfrastructureService::class)->acknowledge($host, $task, $params['state'], $params['result']);
        return response()->json(['data' => true]);
    }

    public function upload(Machine $machine, Request $request)
    {
        $params = $request->validate(['task_id' => 'required|uuid|exists:v2_infra_task,id', 'offset' => 'required|integer|min:0',
            'total_bytes' => 'required|integer|min:1|max:21474836480', 'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'chunk' => 'required|string|max:3000000']);
        $chunk = base64_decode($params['chunk'], true);
        if ($chunk === false || strlen($chunk) > 2097152) abort(422, '无效的备份分片');
        $host = $this->host($machine);
        if (!$host->allows('backups')) abort(409, '主机备份功能尚未启用');
        $offset = DB::transaction(function () use ($host, $params, $chunk) {
            $task = InfrastructureTask::where('host_id', $host->id)->lockForUpdate()->findOrFail($params['task_id']);
            if ($task->action !== 'backup.create' || $task->state !== 'dispatched') abort(409, '此任务不能上传备份');
            $payload = $task->payload();
            $backup = InfrastructureBackup::query()->lockForUpdate()->find($payload['backup_id']);
            if (!$backup) {
                $backup = InfrastructureBackup::create(['id' => $payload['backup_id'], 'host_id' => $host->id, 'task_id' => $task->id,
                    'kind' => $payload['kind'], 'state' => 'uploading', 'filename' => 'panelcore-' . $payload['kind'] . '-' . $payload['backup_id'] . '.tar.gz.enc',
                    'sha256' => $params['sha256'], 'bytes' => 0, 'expected_bytes' => $params['total_bytes'],
                    'encrypted_password' => Crypt::encryptString($payload['password'])]);
            }
            if ($backup->expected_bytes !== (int) $params['total_bytes'] || $backup->sha256 !== $params['sha256']) abort(409, '备份身份与已上传分片不一致');
            if ($backup->state === 'ready') return ['offset' => (int) $backup->bytes, 'state' => 'ready'];
            if ($backup->state === 'failed') return ['offset' => (int) $backup->bytes, 'state' => 'failed'];
            if (!is_dir(dirname($backup->path())) && !mkdir(dirname($backup->path()), 0700, true) && !is_dir(dirname($backup->path()))) abort(500, '备份目录创建失败');
            $file = fopen($backup->path(), 'c+b');
            if (!$file) abort(500, '无法打开备份文件');
            try {
                if (!flock($file, LOCK_EX)) abort(503, '备份文件繁忙');
                $actual = (int) fstat($file)['size'];
                if ((int) $params['offset'] !== $actual) return ['offset' => $actual, 'state' => 'uploading'];
                if ($actual + strlen($chunk) > (int) $params['total_bytes']) abort(422, '备份分片超过预期长度');
                fseek($file, 0, SEEK_END);
                $written = 0;
                while ($written < strlen($chunk)) {
                    $count = fwrite($file, substr($chunk, $written));
                    if ($count === false || $count === 0) abort(500, '备份分片写入失败');
                    $written += $count;
                }
                if (!fflush($file)) abort(500, '备份分片同步失败');
                $backup->bytes = $actual + $written;
                if ($backup->bytes === (int) $params['total_bytes']) {
                    if (!hash_equals($params['sha256'], hash_file('sha256', $backup->path()))) {
                        $backup->state = 'failed';
                        $backup->save();
                        return ['offset' => (int) $backup->bytes, 'state' => 'failed'];
                    }
                    $backup->state = 'ready';
                }
                $backup->save();
                return ['offset' => (int) $backup->bytes, 'state' => $backup->state];
            } finally {
                flock($file, LOCK_UN);
                fclose($file);
                chmod($backup->path(), 0600);
            }
        });
        if ($offset['state'] === 'failed') abort(422, '备份完整性校验失败');
        return response()->json(['data' => $offset]);
    }

    public function download(Machine $machine, Request $request)
    {
        $params = $request->validate(['task_id' => 'required|uuid|exists:v2_infra_task,id', 'offset' => 'sometimes|integer|min:0']);
        $host = $this->host($machine);
        $task = InfrastructureTask::where('host_id', $host->id)->findOrFail($params['task_id']);
        if (!$host->enabled || !in_array($task->action, ['backup.restore', 'backup.deploy'], true) || $task->state !== 'dispatched') abort(403, '此任务无备份下载权限');
        if (!$host->allows(InfrastructureService::ACTION_FEATURES[$task->action])) abort(403, '恢复功能已停用');
        $payload = $task->payload();
        $backup = InfrastructureBackup::findOrFail($payload['backup_id']);
        if ($backup->state !== 'ready' || !is_file($backup->path())) abort(409, '备份尚未就绪');
        $offset = (int) ($params['offset'] ?? 0);
        if ($offset > $backup->bytes) abort(416);
        $file = fopen($backup->path(), 'rb');
        if (!$file) abort(500, '备份文件无法读取');
        fseek($file, $offset);
        $chunk = fread($file, 2097152);
        fclose($file);
        if ($chunk === false) abort(500, '备份分片读取失败');
        return response()->json(['data' => ['chunk' => base64_encode($chunk), 'offset' => $offset, 'total_bytes' => $backup->bytes, 'sha256' => $backup->sha256]]);
    }

    public function event(Machine $machine, Request $request)
    {
        $host = $this->host($machine);
        $params = $request->validate(['agent_event_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/'], 'kind' => 'required|string|max:40', 'message' => 'required|string|max:2000', 'level' => 'required|in:info,warning,error',
            'cluster_id' => 'nullable|integer|exists:v2_infra_cluster,id', 'details' => 'sometimes|array']);
        if (!empty($params['cluster_id'])) {
            $cluster = \App\Models\InfrastructureCluster::findOrFail($params['cluster_id']);
            if (!in_array((int) $host->id, [(int) $cluster->primary_host_id, (int) $cluster->standby_host_id], true)) abort(403);
        }
        if (strlen(json_encode($params['details'] ?? [], JSON_THROW_ON_ERROR)) > 16384) abort(413);
        $event = InfrastructureEvent::firstOrCreate(['host_id' => $host->id, 'agent_event_id' => $params['agent_event_id']],
            ['kind' => $params['kind'], 'message' => $params['message'], 'level' => $params['level'], 'cluster_id' => $params['cluster_id'] ?? null,
                'details' => $params['details'] ?? [], 'created_at' => time()]);
        if ($event->wasRecentlyCreated && in_array($params['level'], ['warning', 'error'], true)
            && Cache::add('infra-notify:' . $host->id . ':' . $params['kind'], true, 300)) {
            try { app(TelegramService::class)->sendMessageWithAdmin($host->name . "\n" . $params['message'], false, ''); }
            catch (\Throwable $exception) { app(InfrastructureService::class)->event('notification.failed', '基础设施通知发送失败', 'error', $host->id); }
        }
        return response()->json(['data' => true]);
    }
}
