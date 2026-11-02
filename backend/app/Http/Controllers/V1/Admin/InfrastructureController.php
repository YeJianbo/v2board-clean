<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudflareCredential;
use App\Models\InfrastructureBackup;
use App\Models\InfrastructureCertificate;
use App\Models\InfrastructureCluster;
use App\Models\InfrastructureEvent;
use App\Models\InfrastructureHost;
use App\Models\InfrastructureMesh;
use App\Models\InfrastructureTask;
use App\Models\Machine;
use App\Services\InfrastructureService;
use App\Services\InfrastructureMeshService;
use App\Services\InfrastructureMigrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InfrastructureController extends Controller
{
    private const DOMAIN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i';
    private const ZONE = '/^[a-f0-9]{32}$/i';
    private const TASK_FIELDS = ['id', 'host_id', 'action', 'state', 'expires_at', 'dispatched_at',
        'completed_at', 'created_at', 'updated_at'];

    private function result($data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function fetch()
    {
        $clusters = InfrastructureCluster::orderBy('id')->get()->map(function ($cluster) {
            $secrets = $cluster->secrets();
            return $cluster->toArray() + ['has_primary_token' => !empty($secrets['primary']['node_token']),
                'has_standby_token' => !empty($secrets['standby']['node_token'])];
        });
        return $this->result(['hosts' => InfrastructureHost::orderBy('name')->get(), 'clusters' => $clusters,
            'certificates' => InfrastructureCertificate::orderBy('domain')->get(), 'mesh_groups' => InfrastructureMesh::orderBy('name')->get(),
            'features' => InfrastructureHost::FEATURES, 'services' => InfrastructureService::SERVICES]);
    }

    private function persist(string $model, array $params, callable $prepare = null)
    {
        return DB::transaction(function () use ($model, $params, $prepare) {
            $id = (int) ($params['id'] ?? 0);
            $item = $id ? $model::query()->lockForUpdate()->findOrFail($id) : new $model();
            if ($id && (int) $item->revision !== (int) ($params['revision'] ?? 0)) abort(409, '配置已修改，请刷新后重试');
            unset($params['id'], $params['revision']);
            if ($prepare) $params = $prepare($item, $params);
            $item->fill($params);
            $item->revision = $id ? (int) $item->revision + 1 : 1;
            $item->save();
            return $item;
        });
    }

    public function saveHost(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_infra_host,id', 'revision' => 'sometimes|integer|min:1',
            'machine_id' => ['required', 'integer', 'exists:v2_machine,id', Rule::unique('v2_infra_host')->ignore($request->input('id'))],
            'name' => 'required|string|max:100', 'enabled' => 'required|boolean', 'features' => 'required|array',
            'settings' => 'required|array', 'settings.site_root' => ['required', 'string', 'max:200', 'regex:~^/(?:srv|var/www|www/wwwroot)/[A-Za-z0-9_/-]+$~'],
            'settings.domain' => ['required', 'string', 'regex:' . self::DOMAIN],
        ]);
        foreach (InfrastructureHost::FEATURES as $feature) $request->validate(['features.' . $feature => 'sometimes|boolean']);
        $params['features'] = array_fill_keys(InfrastructureHost::FEATURES, false);
        foreach (InfrastructureHost::FEATURES as $feature) $params['features'][$feature] = $request->boolean('features.' . $feature);
        $params['settings'] = ['site_root' => rtrim($request->input('settings.site_root'), '/'), 'domain' => strtolower($request->input('settings.domain'))];
        $host = $this->persist(InfrastructureHost::class, $params, function ($host, $params) {
            if ($host->exists && (int) $host->machine_id !== (int) $params['machine_id']) abort(409, '已有主机不能改绑机器，请新建主机配置');
            return $params;
        });
        return $this->result($host);
    }

    public function bootstrap(Request $request)
    {
        $params = $request->validate(['host_id' => 'required|integer|exists:v2_infra_host,id', 'panel_url' => 'required|url|starts_with:https://|max:253']);
        $host = InfrastructureHost::findOrFail($params['host_id']);
        $machine = Machine::findOrFail($host->machine_id);
        $parts = parse_url($params['panel_url']);
        if (empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) abort(422, '请填写面板 HTTPS 根地址');
        return $this->result(['enabled' => false, 'panel_url' => rtrim($params['panel_url'], '/'), 'machine_id' => (int) $machine->id,
            'api_token' => (string) $machine->api_token, 'site_root' => $host->settings['site_root'], 'domain' => $host->settings['domain'],
            'capabilities' => array_fill_keys(InfrastructureHost::FEATURES, false), 'operation_grant' => ['actions' => [], 'expires_at' => 0], 'php_fpm_unit' => 'php8.2-fpm',
            'horizon_program' => 'v2board_horizon', 'database_name' => 'v2board', 'database_user' => 'v2board',
            'managed_domains' => [$host->settings['domain']], 'allow_overwrite' => false,
            'allow_replica_initialize' => false, 'allow_shared_files' => false, 'cluster_mesh_ip' => '',
            'ssh_peer' => ['host' => '', 'port' => 22, 'user' => 'root', 'key_file' => '/etc/panelcore/peer.key', 'known_hosts_file' => '/etc/panelcore/known_hosts']]);
    }

    public function saveCertificate(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_infra_certificate,id', 'revision' => 'sometimes|integer|min:1',
            'host_id' => 'required|integer|exists:v2_infra_host,id', 'credential_id' => 'required|integer|exists:v2_cloudflare_credential,id',
            'zone_id' => ['required', 'string', 'regex:' . self::ZONE], 'domain' => ['required', 'string', 'regex:' . self::DOMAIN],
            'email' => 'required|email|max:254', 'enabled' => 'required|boolean', 'staging' => 'required|boolean',
        ]);
        $params['zone_id'] = strtolower($params['zone_id']);
        $params['domain'] = strtolower($params['domain']);
        if (!CloudflareCredential::findOrFail($params['credential_id'])->permitsZone($params['zone_id'])) abort(422, '凭据未授权此 Zone');
        return $this->result($this->persist(InfrastructureCertificate::class, $params));
    }

    public function saveCluster(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_infra_cluster,id', 'revision' => 'sometimes|integer|min:1',
            'name' => ['required', 'string', 'max:100', Rule::unique('v2_infra_cluster')->ignore($request->input('id'))],
            'primary_host_id' => 'required|integer|exists:v2_infra_host,id', 'standby_host_id' => 'required|integer|exists:v2_infra_host,id|different:primary_host_id',
            'credential_id' => 'nullable|integer|exists:v2_cloudflare_credential,id', 'enabled' => 'required|boolean',
            'sync_enabled' => 'required|boolean', 'failover_enabled' => 'required|boolean', 'split_enabled' => 'required|boolean',
            'settings' => 'required|array', 'settings.zone_id' => ['nullable', 'string', 'regex:' . self::ZONE],
            'settings.arbiter_url' => 'nullable|url|starts_with:https://|max:253',
            'settings.primary_origin' => 'nullable|url|starts_with:https://|max:253', 'settings.standby_origin' => 'nullable|url|starts_with:https://|max:253',
            'settings.primary_address' => 'nullable|ip', 'settings.standby_address' => 'nullable|ip',
            'settings.primary_mesh_ip' => 'nullable|ip', 'settings.standby_mesh_ip' => 'nullable|ip',
            'settings.max_replica_lag' => 'required|integer|min:0|max:300', 'settings.failure_threshold' => 'required|integer|min:3|max:30',
            'settings.max_file_age' => 'required|integer|min:60|max:86400', 'settings.check_interval' => 'required|integer|min:10|max:300',
            'settings.dns_records' => 'sometimes|array|max:100', 'settings.dns_records.*.id' => ['required', 'string', 'distinct', 'regex:' . self::ZONE],
            'settings.dns_records.*.type' => 'required|in:A,AAAA', 'settings.dns_records.*.name' => ['required', 'regex:' . self::DOMAIN],
            'settings.dns_records.*.target' => 'required|in:primary,standby', 'settings.dns_records.*.primary_content' => 'required|ip',
            'settings.dns_records.*.standby_content' => 'required|ip',
            'secrets' => 'sometimes|array', 'secrets.primary_node_token' => 'nullable|string|min:32|max:256', 'secrets.standby_node_token' => 'nullable|string|min:32|max:256',
            'secrets.replication_password' => 'nullable|string|min:32|max:128', 'secrets.notify_url' => 'nullable|url|starts_with:https://|max:1000',
        ]);
        $settings = $params['settings'];
        $settings = array_intersect_key($settings, array_flip(['zone_id', 'arbiter_url', 'primary_origin', 'standby_origin', 'primary_address', 'standby_address',
            'primary_mesh_ip', 'standby_mesh_ip', 'max_replica_lag', 'failure_threshold', 'max_file_age', 'check_interval', 'dns_records']));
        $settings['zone_id'] = strtolower($settings['zone_id'] ?? '');
        $settings['dns_records'] = array_map(function ($record) {
            $record = array_intersect_key($record, array_flip(['id', 'type', 'name', 'target', 'primary_content', 'standby_content']));
            $filter = $record['type'] === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
            if (!filter_var($record['primary_content'], FILTER_VALIDATE_IP, $filter) || !filter_var($record['standby_content'], FILTER_VALIDATE_IP, $filter)) abort(422, 'DNS 地址与记录类型不匹配');
            return $record;
        }, $settings['dns_records'] ?? []);
        if (!empty($params['credential_id']) && !empty($settings['zone_id']) && !CloudflareCredential::findOrFail($params['credential_id'])->permitsZone($settings['zone_id'])) abort(422, '凭据未授权此 Zone');
        if ($params['failover_enabled'] && (empty($settings['arbiter_url']) || empty($settings['primary_origin']) || empty($settings['primary_address']) || empty($settings['standby_origin']) || empty($params['credential_id']) || empty($settings['zone_id']) || !$settings['dns_records'])) abort(422, '故障转移配置不完整');
        if ($params['split_enabled'] && (empty($settings['primary_mesh_ip']) || empty($settings['standby_mesh_ip']))) abort(422, '双机共同处理需要两台机器的 Mesh 内网地址');
        $incoming = $params['secrets'] ?? [];
        unset($params['secrets']);
        $params['settings'] = $settings;
        if ($params['enabled'] && InfrastructureCluster::where('enabled', true)->when($params['id'] ?? null, fn ($query, $id) => $query->where('id', '<>', $id))
            ->where(function ($query) use ($params) {
                $ids = [$params['primary_host_id'], $params['standby_host_id']];
                $query->whereIn('primary_host_id', $ids)->orWhereIn('standby_host_id', $ids);
            })->exists()) abort(409, '主机已属于其他已启用的主备集群');
        $cluster = $this->persist(InfrastructureCluster::class, $params, function ($cluster, $params) use ($incoming) {
            $secrets = $cluster->secrets();
            foreach (['primary', 'standby'] as $role) if (!empty($incoming[$role . '_node_token'])) $secrets[$role]['node_token'] = $incoming[$role . '_node_token'];
            foreach (['replication_password', 'notify_url'] as $key) if (!empty($incoming[$key])) $secrets[$key] = $incoming[$key];
            if (empty($secrets['replication_password'])) $secrets['replication_password'] = Str::random(64);
            if ($params['failover_enabled'] && (empty($secrets['primary']['node_token']) || empty($secrets['standby']['node_token']))) abort(422, '请分别填写主机和备用机的仲裁 Token');
            $params['encrypted_secrets'] = Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR));
            return $params;
        });
        return $this->result($cluster);
    }

    public function saveMesh(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_infra_mesh,id', 'revision' => 'sometimes|integer|min:1',
            'name' => ['required', 'string', 'max:100', Rule::unique('v2_infra_mesh')->ignore($request->input('id'))],
            'credential_id' => 'required|integer|exists:v2_cloudflare_credential,id', 'enabled' => 'required|boolean',
            'settings' => 'required|array', 'settings.host_ids' => 'required|array|min:1|max:200', 'settings.host_ids.*' => 'required|integer|distinct|exists:v2_infra_host,id',
            'settings.allowed_pairs' => 'sometimes|array|max:500', 'settings.allowed_pairs.*.source_host_id' => 'required|integer|exists:v2_infra_host,id',
            'settings.allowed_pairs.*.destination_host_id' => 'required|integer|exists:v2_infra_host,id',
            'settings.allowed_pairs.*.ports' => 'required|array|min:1|max:100', 'settings.allowed_pairs.*.ports.*' => 'integer|min:1|max:65535',
        ]);
        if (!CloudflareCredential::findOrFail($params['credential_id'])->account_id) abort(422, 'Mesh 凭据需要账户 ID');
        $hostIds = array_map('intval', $params['settings']['host_ids']);
        $pairs = [];
        foreach ($params['settings']['allowed_pairs'] ?? [] as $pair) {
            if (!in_array((int) $pair['source_host_id'], $hostIds, true) || !in_array((int) $pair['destination_host_id'], $hostIds, true)) abort(422, '访问规则的机器必须属于此组');
            $pairs[] = ['source_host_id' => (int) $pair['source_host_id'], 'destination_host_id' => (int) $pair['destination_host_id'], 'ports' => array_values(array_unique(array_map('intval', $pair['ports'])))];
        }
        $params['settings'] = ['host_ids' => $hostIds, 'allowed_pairs' => $pairs];
        return $this->result($this->persist(InfrastructureMesh::class, $params));
    }

    public function tasks(Request $request)
    {
        $params = $request->validate(['host_id' => 'nullable|integer|exists:v2_infra_host,id', 'before' => 'nullable|integer|min:1',
            'task_id' => 'nullable|uuid', 'summary' => 'sometimes|boolean']);
        $query = InfrastructureTask::when($params['host_id'] ?? null, fn ($query, $id) => $query->where('host_id', $id));
        if (!empty($params['task_id'])) {
            return $this->result($query->select(array_merge(self::TASK_FIELDS, ['result']))->findOrFail($params['task_id']));
        }
        $query->when($params['before'] ?? null, fn ($query, $before) => $query->where('created_at', '<', $before))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->select(self::TASK_FIELDS);
        if (!empty($params['summary'])) {
            // Keep a bounded message without transferring result bodies or encrypted task payloads.
            $expression = DB::connection()->getDriverName() === 'sqlite'
                ? "CASE WHEN json_type(result, '$.message') = 'text' THEN SUBSTR(json_extract(result, '$.message'), 1, 512) ELSE NULL END"
                : "CASE WHEN JSON_TYPE(JSON_EXTRACT(result, '$.message')) = 'STRING' THEN SUBSTR(JSON_UNQUOTE(JSON_EXTRACT(result, '$.message')), 1, 512) ELSE NULL END";
            $query->selectRaw($expression . ' AS result_message');
        } else {
            $query->addSelect('result');
        }
        return $this->result($query->get());
    }

    public function meshInspect(Request $request, InfrastructureMeshService $service)
    {
        $params = $request->validate(['mesh_id' => 'required|integer|exists:v2_infra_mesh,id']);
        return $this->result($service->inspect(InfrastructureMesh::findOrFail($params['mesh_id'])));
    }

    public function meshEnroll(Request $request, InfrastructureMeshService $service)
    {
        $params = $request->validate(['mesh_id' => 'required|integer|exists:v2_infra_mesh,id', 'host_id' => 'required|integer|exists:v2_infra_host,id']);
        return $this->result($service->enroll(InfrastructureMesh::findOrFail($params['mesh_id']), (int) $params['host_id']));
    }

    public function meshApply(Request $request, InfrastructureMeshService $service)
    {
        $params = $request->validate(['mesh_id' => 'required|integer|exists:v2_infra_mesh,id', 'confirm_name' => 'required|string|max:100']);
        $group = InfrastructureMesh::findOrFail($params['mesh_id']);
        if ($params['confirm_name'] !== $group->name) abort(422, '请确认 Mesh 组名称');
        return $this->result($service->applyPolicy($group));
    }

    public function backups()
    {
        return $this->result(InfrastructureBackup::orderByDesc('created_at')->orderByDesc('id')->limit(100)->get([
            'id', 'host_id', 'task_id', 'kind', 'state', 'filename', 'sha256', 'bytes', 'expected_bytes', 'created_at', 'updated_at',
        ]));
    }

    private function migrationInput(Request $request): array
    {
        $rules = ['backup_id' => 'required|uuid|exists:v2_infra_backup,id',
            'site_root' => ['sometimes', 'string', 'max:200', 'regex:~^/(?:srv|var/www|www/wwwroot)/[A-Za-z0-9_/-]+$~'],
            'domain' => ['sometimes', 'string', 'regex:' . self::DOMAIN],
            'database_name' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_]{1,32}$/'],
            'database_user' => ['sometimes', 'string', 'not_in:root', 'regex:/^[A-Za-z0-9_]{1,32}$/']];
        $rules += ['private_key' => 'prohibited', 'address' => 'prohibited', 'fingerprint' => 'prohibited'];
        $input = $request->validate($rules);
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $input['site_root'] ?? '')) abort(422, '站点目录不能包含上级路径');
        return $input;
    }

    public function migrationPackage(Request $request, InfrastructureMigrationService $service)
    {
        $input = $this->migrationInput($request);
        $backup = $service->backup($input['backup_id']);
        $path = $service->package($backup, $service->settings($backup, $input));
        return $service->download($path, 'panelcore-migration-' . $backup->id . '.tar.gz');
    }

    public function migrationPassword(Request $request, InfrastructureMigrationService $service)
    {
        $input = $this->migrationInput($request);
        return $this->result(['password' => $service->backup($input['backup_id'])->password()]);
    }

    public function migrationCommand(Request $request, InfrastructureMigrationService $service)
    {
        $input = $this->migrationInput($request);
        $backup = $service->backup($input['backup_id']);
        return $this->result($service->ticket($backup, $service->settings($backup, $input), $request->getSchemeAndHttpHost()));
    }

    public function events(Request $request)
    {
        $params = $request->validate(['cluster_id' => 'nullable|integer|exists:v2_infra_cluster,id', 'before' => 'nullable|integer|min:1',
            'summary' => 'sometimes|boolean']);
        $query = InfrastructureEvent::when($params['cluster_id'] ?? null, fn ($query, $id) => $query->where('cluster_id', $id))
            ->when($params['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))->orderByDesc('id')->limit(100);
        if (!empty($params['summary'])) $query->select(['id', 'cluster_id', 'host_id', 'agent_event_id', 'kind', 'level', 'message', 'created_at']);
        return $this->result($query->get());
    }

    public function createTask(Request $request, InfrastructureService $service)
    {
        $params = $request->validate(['host_id' => 'required|integer|exists:v2_infra_host,id', 'action' => ['required', Rule::in(array_keys(InfrastructureService::ACTION_FEATURES))],
            'service' => ['sometimes', Rule::in(InfrastructureService::SERVICES)], 'lines' => 'sometimes|integer|min:20|max:300',
            'kind' => 'sometimes|in:database,migration', 'backup_id' => 'sometimes|uuid|exists:v2_infra_backup,id',
            'certificate_id' => 'sometimes|integer|exists:v2_infra_certificate,id', 'cluster_id' => 'sometimes|integer|exists:v2_infra_cluster,id',
            'schedule_enabled' => 'sometimes|boolean', 'overwrite' => 'sometimes|boolean', 'confirm_name' => 'sometimes|string|max:100', 'confirm_replica_name' => 'sometimes|string|max:100']);
        $host = InfrastructureHost::findOrFail($params['host_id']);
        $action = $params['action'];
        $payload = [];
        if (str_starts_with($action, 'service.')) {
            if (empty($params['service'])) abort(422, '请选择服务');
            $payload = ['service' => $params['service'], 'lines' => $params['lines'] ?? 100];
        } elseif ($action === 'schedule.save') {
            if (!array_key_exists('schedule_enabled', $params)) abort(422, '请选择定时任务状态');
            $payload = ['schedule_enabled' => (bool) $params['schedule_enabled']];
        } elseif ($action === 'backup.create') {
            $payload = ['kind' => $params['kind'] ?? 'migration', 'backup_id' => (string) Str::uuid(), 'password' => Str::random(64)];
        } elseif (in_array($action, ['backup.restore', 'backup.deploy'], true)) {
            if (empty($params['backup_id'])) abort(422, '请选择备份');
            $backup = InfrastructureBackup::findOrFail($params['backup_id']);
            if ($backup->state !== 'ready' || !is_file($backup->path())) abort(409, '备份尚未传输完成');
            if ((int) $backup->host_id === (int) $host->id) abort(422, '不能向备份来源主机直接执行覆盖恢复');
            if ($action === 'backup.deploy' && $backup->kind !== 'migration') abort(422, '部署需要完整迁移备份');
            $overwrite = (bool) ($params['overwrite'] ?? false);
            if ($overwrite && ($params['confirm_name'] ?? '') !== $host->name) abort(422, '覆盖恢复需要确认目标主机名称');
            if ($backup->kind === 'database' && !$overwrite) abort(422, '仅数据库恢复需要明确允许覆盖目标数据库');
            $payload = ['backup_id' => $backup->id, 'kind' => $backup->kind, 'password' => $backup->password(), 'sha256' => $backup->sha256, 'bytes' => $backup->bytes, 'overwrite' => $overwrite, 'standby' => true];
        } elseif (str_starts_with($action, 'certificate.')) {
            if (empty($params['certificate_id'])) abort(422, '请选择证书');
            $certificate = InfrastructureCertificate::where('host_id', $host->id)->findOrFail($params['certificate_id']);
            $payload = $service->certificatePayload($certificate);
        } elseif (str_starts_with($action, 'cluster.')) {
            if (empty($params['cluster_id'])) abort(422, '请选择集群');
            $cluster = InfrastructureCluster::findOrFail($params['cluster_id']);
            if ($action === 'cluster.configure' && (int) $host->id === (int) $cluster->primary_host_id
                && ($params['confirm_replica_name'] ?? '') !== InfrastructureHost::findOrFail($cluster->standby_host_id)->name) abort(422, '初始化复制会覆盖备用数据库，请确认备用主机名称');
            if ($action === 'cluster.sync' && !$cluster->sync_enabled) abort(409, '主备同步尚未启用');
            if ($action === 'cluster.failover' && !$cluster->failover_enabled) abort(409, '故障转移尚未启用');
            if ($action === 'cluster.split' && !$cluster->split_enabled) abort(409, '双机共同处理尚未启用');
            $payload = $service->clusterPayload($cluster, $host);
        } elseif (in_array($action, ['mesh.status', 'mesh.disconnect'], true)) {
            if ($action === 'mesh.disconnect' && ($params['confirm_name'] ?? '') !== $host->name) abort(422, '请确认要断开 Mesh 的主机名称');
        } else {
            abort(422, 'Mesh 操作请使用 Mesh 管理接口');
        }
        if (in_array($action, ['service.stop', 'service.restart', 'backup.restore', 'backup.deploy', 'cluster.configure', 'cluster.failover', 'cluster.split'], true) && ($params['confirm_name'] ?? '') !== $host->name) abort(422, '请确认目标主机名称');
        return $this->result($service->enqueue($host, $action, $payload));
    }

    public function resolveTask(Request $request, InfrastructureService $service)
    {
        $params = $request->validate(['task_id' => 'required|uuid|exists:v2_infra_task,id', 'confirmed_stopped' => 'required|accepted', 'reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($params, $service) {
            $task = InfrastructureTask::query()->lockForUpdate()->findOrFail($params['task_id']);
            if (!in_array($task->state, ['unknown', 'queued'], true)) abort(409, '仅可取消未派发任务，或处理已经确认停止的结果不明任务');
            $task->state = 'cancelled';
            if ($task->action === 'migration.ssh') {
                $payload = $task->payload(); $payload['private_key'] = '';
                $task->encrypted_payload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            }
            $task->result = ['message' => $params['reason'], 'manually_resolved' => true];
            $task->completed_at = time();
            $task->save();
            $service->event('task.resolved', $params['reason'], 'warning', $task->host_id, null, ['task_id' => $task->id]);
        });
        return $this->result(true);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['kind' => 'required|in:host,certificate,cluster,mesh,backup', 'id' => 'required', 'revision' => 'sometimes|integer|min:1']);
        $models = ['host' => InfrastructureHost::class, 'certificate' => InfrastructureCertificate::class, 'cluster' => InfrastructureCluster::class,
            'mesh' => InfrastructureMesh::class, 'backup' => InfrastructureBackup::class];
        DB::transaction(function () use ($params, $models) {
            $item = $models[$params['kind']]::query()->lockForUpdate()->findOrFail($params['id']);
            if ($params['kind'] !== 'backup' && (int) $item->revision !== (int) ($params['revision'] ?? 0)) abort(409, '配置已修改，请刷新后重试');
            if ($params['kind'] !== 'backup' && $item->enabled) abort(409, '请先停用此配置');
            if ($params['kind'] === 'mesh' && (!empty($item->status['nodes']) || !empty($item->status['policy']))) abort(409, '此组已有 Cloudflare 资源或本机规则，请先在对应主机和 Cloudflare 清理后再删除配置');
            if ($params['kind'] === 'host' && InfrastructureTask::where('host_id', $item->id)->exists()) abort(409, '主机仍有任务记录，请保留用于追溯');
            if ($params['kind'] === 'host' && InfrastructureMesh::get()->contains(fn ($mesh) => in_array((int) $item->id, array_map('intval', $mesh->settings['host_ids'] ?? []), true))) abort(409, '主机仍被 Mesh 访问组引用');
            if ($params['kind'] === 'backup') {
                if (InfrastructureTask::whereIn('state', ['queued', 'dispatched', 'unknown'])->get()->contains(fn ($task) => ($task->payload()['backup_id'] ?? '') === $item->id)) abort(409, '备份仍被待处理任务引用');
                if (is_file($item->path()) && !unlink($item->path())) abort(500, '备份文件删除失败');
            }
            $item->delete();
        });
        return $this->result(true);
    }
}
