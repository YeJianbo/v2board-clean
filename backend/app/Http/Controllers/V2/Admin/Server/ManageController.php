<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\ServerGroup;
use App\Services\ServerMetadataService;
use App\Services\ServerService;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageController extends Controller
{
    public static function encodeId($id, $type)
    {
        $prefixes = [
            'shadowsocks' => 1000000,
            'vmess' => 2000000,
            'trojan' => 3000000,
            'tuic' => 4000000,
            'hysteria' => 5000000,
            'vless' => 6000000,
            'anytls' => 7000000,
            'v2node' => 8000000,
        ];
        return ($prefixes[strtolower($type)] ?? 0) + $id;
    }

    public static function decodeId($encodedId)
    {
        $prefixes = [
            'shadowsocks' => 1000000,
            'vmess' => 2000000,
            'trojan' => 3000000,
            'tuic' => 4000000,
            'hysteria' => 5000000,
            'vless' => 6000000,
            'anytls' => 7000000,
            'v2node' => 8000000,
        ];
        foreach ($prefixes as $type => $prefix) {
            if ($encodedId >= $prefix && $encodedId < $prefix + 1000000) {
                return [
                    'id' => $encodedId - $prefix,
                    'type' => $type
                ];
            }
        }
        return [
            'id' => $encodedId,
            'type' => null
        ];
    }

    private function getServerModelClass($type)
    {
        $classes = [
            'shadowsocks' => \App\Models\ServerShadowsocks::class,
            'vmess' => \App\Models\ServerVmess::class,
            'trojan' => \App\Models\ServerTrojan::class,
            'tuic' => \App\Models\ServerTuic::class,
            'hysteria' => \App\Models\ServerHysteria::class,
            'vless' => \App\Models\ServerVless::class,
            'anytls' => \App\Models\ServerAnytls::class,
            'v2node' => \App\Models\ServerV2node::class,
        ];
        return $classes[strtolower($type)] ?? null;
    }

    public function getNodes(Request $request)
    {
        $includeParent = $request->boolean('include_parent', true);
        $serverService = new ServerService();
        $rawServers = collect(
            app(ServerMetadataService::class)->decorateServers($serverService->getAllServers())
        );
        $groupIds = $rawServers
            ->flatMap(function ($item) {
                return is_array($item['group_id'] ?? null) ? $item['group_id'] : [];
            })
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter()
            ->unique()
            ->values();
        $groupsById = $groupIds->isEmpty()
            ? collect()
            : ServerGroup::whereIn('id', $groupIds)->get(['name', 'id'])->keyBy('id');
        $serversByKey = $includeParent
            ? $rawServers->keyBy(function ($item) {
                return strtolower((string) ($item['type'] ?? '')) . ':' . (int) ($item['id'] ?? 0);
            })
            : null;

        $servers = $rawServers->map(function ($item) use ($groupsById, $serversByKey, $includeParent) {
            $rawId = $item['id'];
            $type = $item['type'];
            $rawParentId = (int) ($item['parent_id'] ?? 0);
            $encodedId = self::encodeId($rawId, $type);
            $item['id'] = $encodedId;
            if ($rawParentId > 0) {
                $item['parent_id'] = self::encodeId($rawParentId, $type);
            }
            $item['groups'] = collect($item['group_id'] ?? [])
                ->map(function ($groupId) use ($groupsById) {
                    return $groupsById->get((int) $groupId);
                })
                ->filter()
                ->values();
            $item['group_ids'] = array_map('strval', $item['group_id'] ?? []);
            $item['route_ids'] = array_map('strval', $item['route_id'] ?? []);

            if ($includeParent) {
                $item['parent'] = null;
            }
            if ($includeParent && $rawParentId > 0) {
                $parent = $serversByKey->get(strtolower((string) $type) . ':' . $rawParentId);
                if ($parent) {
                    $parent['id'] = self::encodeId($parent['id'], $type);
                    if (!empty($parent['parent_id'])) {
                        $parent['parent_id'] = self::encodeId($parent['parent_id'], $type);
                    }
                    $item['parent'] = $parent;
                }
            }
            return $item;
        })->toArray();
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        // The sort payload is a JSON root array. Read the untouched HTTP body:
        // admin middleware merges its user field into Laravel's JSON input bag.
        $params = json_decode((string) $request->getContent(), true);
        if (!is_array($params)) {
            $params = [];
        }
        $validator = Validator::make($params, [
            '*' => 'required|array',
            '*.id' => 'required|integer|min:1000001|max:8999999',
            '*.order' => 'required|integer|min:1|max:100000'
        ]);
        $params = $validator->validate();

        if (count($params) > 10000) {
            return $this->fail([422, '一次最多排序 10000 个节点']);
        }

        try {
            $grouped = [];
            $seen = [];
            foreach ($params as $item) {
                $encodedId = (int) $item['id'];
                if (isset($seen[$encodedId])) {
                    return $this->fail([422, '排序数据包含重复节点']);
                }
                $seen[$encodedId] = true;
                $decoded = self::decodeId($encodedId);
                if (!$decoded['type']) {
                    return $this->fail([422, '排序数据包含无效节点 ID']);
                }
                $grouped[$decoded['type']][(int) $decoded['id']] = (int) $item['order'];
            }

            DB::transaction(function () use ($grouped) {
                foreach ($grouped as $type => $orders) {
                    $modelClass = $this->getServerModelClass($type);
                    if (!$modelClass || !$orders) {
                        continue;
                    }

                    $table = (new $modelClass())->getTable();
                    $ids = array_keys($orders);
                    $bindings = [];
                    $cases = [];
                    foreach ($orders as $id => $order) {
                        $cases[] = 'WHEN ? THEN ?';
                        $bindings[] = $id;
                        $bindings[] = $order;
                    }
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    array_push($bindings, ...$ids);
                    DB::update(
                        "UPDATE `{$table}` SET `sort` = CASE `id` " . implode(' ', $cases) . " END WHERE `id` IN ({$placeholders})",
                        $bindings
                    );
                }
            }, 3);

            foreach (array_keys($grouped) as $type) {
                $this->invalidateServerCollection($type);
            }
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success([
            'updated' => count($params),
        ]);
    }

    public function save(ServerSave $request)
    {
        $metadata = $this->validateServerSaveRequest($request);
        $params = $this->serverSaveParameters($request->validated());
        $type = $request->input('type');
        unset($params['type']);
        $modelClass = $this->getServerModelClass($type);
        if (!$modelClass) {
            return $this->fail([400, '不支持的节点类型']);
        }

        if (isset($params['group_ids'])) {
            $params['group_id'] = $params['group_ids'];
            unset($params['group_ids']);
        }
        if (isset($params['route_ids'])) {
            $params['route_id'] = $params['route_ids'];
            unset($params['route_ids']);
        }

        if (isset($params['parent_id']) && $params['parent_id']) {
            $decodedParent = self::decodeId((int)$params['parent_id']);
            $params['parent_id'] = $decodedParent['id'];
        }

        if ($request->input('id')) {
            $decoded = self::decodeId((int)$request->input('id'));
            if ($decoded['type'] !== null && $decoded['type'] !== strtolower($type)) {
                return $this->fail([400, '节点 ID 与协议类型不一致']);
            }
            $server = $modelClass::find($decoded['id']);
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server = $this->saveServerWithMetadata($server, $params, $type, $metadata, $this->machineChangeCallback($type));
                return $this->success(true);
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            $server = $this->saveServerWithMetadata(new $modelClass(), $params, $type, $metadata, $this->machineChangeCallback($type));
            return $this->success(true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        $decoded = self::decodeId($request->id);
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $update = [];
        if (array_key_exists('show', $params)) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }
        if (array_key_exists('enabled', $params)) {
            $update['enabled'] = (bool) $params['enabled'];
        }

        try {
            $this->saveServerWithMetadata($server, $update, $decoded['type'], null, $this->machineChangeCallback($decoded['type']));
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $decoded = self::decodeId($request->id);
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        try {
            $deleted = $this->deleteServerWithMetadata($server, $decoded['type']);
            if ($decoded['type'] === 'v2node') {
                NodeSyncService::scheduleMachineNodesChanged([$deleted->machine_id, $deleted->relay_machine_id]);
            }
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    private function machineChangeCallback(string $type): ?callable
    {
        if ($type !== 'v2node') return null;
        return static function ($before, $after): void {
            NodeSyncService::scheduleMachineNodesChanged([
                $before->machine_id, $before->relay_machine_id, $after->machine_id, $after->relay_machine_id,
            ]);
        };
    }

    private function lockBatchMachineIds(string $type, array $ids): array
    {
        if ($type !== 'v2node') return [];
        $machineIds = [];
        foreach (DB::table('v2_server_v2node')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()
            ->get(['machine_id', 'relay_machine_id']) as $node) {
            foreach ([$node->machine_id, $node->relay_machine_id] as $id) {
                if ((int) $id > 0) $machineIds[(int) $id] = (int) $id;
            }
        }
        return $machineIds;
    }

    private function groupServerIds(array $ids): array
    {
        $grouped = [];
        foreach ($ids as $encodedId) {
            $decoded = self::decodeId($encodedId);
            if ($this->getServerModelClass($decoded['type'])) {
                $grouped[$decoded['type']][$decoded['id']] = $decoded['id'];
            }
        }
        return $grouped;
    }

    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            $grouped = $this->groupServerIds($ids);
            DB::transaction(function () use ($grouped) {
                $machines = [];
                $runtimeChanged = false;
                foreach ($grouped as $type => $serverIds) {
                    $modelClass = $this->getServerModelClass($type);
                    foreach (array_chunk(array_values($serverIds), 500) as $batch) {
                        $bindings = $this->lockBatchMachineIds($type, $batch);
                        if ($modelClass::whereIn('id', $batch)->delete() > 0 && $type === 'v2node') {
                            $runtimeChanged = true;
                            $machines += $bindings;
                        }
                    }
                    app(ServerMetadataService::class)->deleteMany($type, array_values($serverIds));
                    $this->invalidateServerCollection($type);
                }
                if ($runtimeChanged) NodeSyncService::scheduleMachineNodesChanged(array_values($machines));
            });
            return $this->success(true);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $decoded = self::decodeId($request->id);
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $this->saveServerWithMetadata($server, ['u' => 0, 'd' => 0], $decoded['type'], null);
            return $this->success(true);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            $grouped = $this->groupServerIds($ids);
            DB::transaction(function () use ($grouped) {
                foreach ($grouped as $type => $serverIds) {
                    $modelClass = $this->getServerModelClass($type);
                    foreach (array_chunk(array_values($serverIds), 500) as $batch) {
                        $modelClass::whereIn('id', $batch)->update([
                            'u' => 0,
                            'd' => 0,
                        ]);
                    }
                    $this->invalidateServerCollection($type);
                }
            });
            return $this->success(true);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            $grouped = $this->groupServerIds($ids);
            $changedTypes = DB::transaction(function () use ($grouped, $update) {
                $changed = [];
                $machines = [];
                foreach ($grouped as $type => $serverIds) {
                    $modelClass = $this->getServerModelClass($type);
                    foreach (array_chunk(array_values($serverIds), 500) as $batch) {
                        $bindings = $this->lockBatchMachineIds($type, $batch);
                        if ($modelClass::whereIn('id', $batch)->update($update) > 0) {
                            $changed[$type] = true;
                            if ($type === 'v2node') $machines += $bindings;
                        }
                    }
                }
                if (isset($changed['v2node'])) {
                    if (!empty($update['machine_id'])) $machines[(int) $update['machine_id']] = (int) $update['machine_id'];
                    NodeSyncService::scheduleMachineNodesChanged(array_values($machines));
                }
                return array_keys($changed);
            });
            foreach ($changedTypes as $type) {
                $this->invalidateServerCollection($type);
            }
            return $this->success(true);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    public function copy(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $decoded = self::decodeId($request->input('id'));
        $modelClass = $this->getServerModelClass($decoded['type']);
        if (!$modelClass) {
            return $this->fail([400202, '服务器类型不支持']);
        }

        $server = $modelClass::find($decoded['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $overrides = ['show' => 0, 'u' => 0, 'd' => 0];
        if (array_key_exists('code', $server->getAttributes())) {
            $overrides['code'] = null;
        }
        if ($decoded['type'] === 'v2node') {
            // A copied runtime node must not compete with its source's listeners.
            $overrides['machine_id'] = null;
            $overrides['relay_machine_id'] = null;
        }
        try {
            $this->copyServerWithMetadata($server, $decoded['type'], $overrides);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, '复制失败']);
        }

        return $this->success(true);
    }

    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        $contents = '';
        $contents .= pack('C', $configId);
        $contents .= pack('n', 0x0020);
        $contents .= pack('n', 32) . $publicKey;
        $contents .= pack('n', 8);
        $contents .= pack('nn', 0x0001, 0x0001);
        $contents .= pack('nn', 0x0001, 0x0003);
        $contents .= pack('C', 0);
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);

        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }

    public function connectivityTestResult(Request $request)
    {
        return app(\App\Http\Controllers\V1\Admin\Server\ManageController::class)
            ->connectivityTestResult($request);
    }
}
