<?php
namespace App\Services;

use App\Models\ServerMetadata;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NodePresentationService
{
    public function preview(string $operation, array $arguments): array
    {
        if ($operation === 'sort') {
            $args = Validator::make($arguments, ['keys' => 'required|array|min:1|max:500', 'keys.*' => 'required|string|max:64|distinct'])->validate();
            $nodes = collect(app(ServerService::class)->getPresentationServers())->keyBy(fn ($n) => $n['type'] . ':' . $n['id']);
            foreach ($args['keys'] as $key) abort_unless($nodes->has($key), 422, '节点不存在：' . $key);
            $keys = array_merge($args['keys'], array_values(array_diff($nodes->keys()->all(), $args['keys'])));
            $changes = array_map(fn ($key, $index) => ['key' => $key, 'value' => $index + 1], $keys, array_keys($keys));
            $field = 'sort';
        } else {
            abort_unless(in_array($operation, ['rename', 'ip', 'visibility'], true), 422, '不支持的节点操作');
            $args = Validator::make($arguments, ['changes' => 'required|array|min:1|max:200', 'changes.*.key' => 'required|string|max:64|distinct',
                'changes.*.value' => $operation === 'visibility' ? 'required|boolean' : ($operation === 'ip' ? 'required|ip' : 'required|string|max:200')])->validate();
            $changes = $args['changes'];
            $references = array_map(static function ($change) {
                [$type, $id] = array_pad(explode(':', $change['key'], 2), 2, null);
                return ['server_type' => $type, 'server_id' => (int) $id];
            }, $changes);
            $nodes = collect(app(ServerService::class)->getPresentationServers($references))->keyBy(fn ($n) => $n['type'] . ':' . $n['id']);
            $field = $operation === 'visibility' ? 'show' : ($operation === 'ip' ? 'entry_host' : 'name');
        }
        $entries = collect();
        if ($field === 'entry_host') {
            $selected = [];
            foreach ($changes as $change) {
                $node = $nodes->get($change['key']);
                abort_unless($node, 422, '节点不存在：' . $change['key']);
                $selected[] = $node;
            }
            $entries = app(ServerMetadataService::class)->forServers($selected, ['entry_host']);
        }
        $rows = [];
        foreach ($changes as $change) {
            $node = $nodes->get($change['key']);
            abort_unless($node, 422, '节点不存在：' . $change['key']);
            $before = $node[$field] ?? null;
            if ($field === 'show') { $before = (int) (bool) $before; $change['value'] = (int) (bool) $change['value']; }
            if ($field === 'entry_host') $before = $entries->get($change['key'])?->entry_host;
            if ((string) $before === (string) $change['value']) continue;
            $rows[] = ['key' => $change['key'], 'type' => $node['type'], 'id' => (int) $node['id'], 'name' => $node['name'], 'field' => $field,
                'before' => $field === 'entry_host' ? ($before ?: $node['host']) : $before, 'expected' => $before, 'after' => $change['value'], 'port' => $node['port']];
        }
        return $rows;
    }

    public function previewEntryMachine(array $arguments): array
    {
        $args = Validator::make($arguments, ['machine_id' => 'required|integer|exists:v2_machine,id', 'host' => 'required|ip'])->validate();
        $preview = app(SubscriptionEntryService::class)->preview((int) $args['machine_id'], $args['host']);
        $rows = [];
        foreach ($preview as $row) {
            if ($row['before'] === $row['after']) continue;
            [$type, $id] = explode(':', $row['key'], 2);
            $rows[] = ['key' => $row['key'], 'type' => $type, 'id' => (int) $id, 'name' => $row['name'], 'field' => 'entry_host',
                'before' => $row['before'], 'expected' => $row['original_override'], 'after' => $row['after'], 'port' => $row['port'],
                'metadata_id' => (int) $row['metadata_id'], 'entry_machine_id' => (int) $args['machine_id']];
        }
        return $rows;
    }

    public function assertCurrent(array $rows): void
    {
        DB::transaction(fn () => $this->lockedRows($rows));
    }

    private function lockedRows(array $rows): array
    {
        abort_unless(count($rows) <= 1000, 422, '一次最多修改 1000 个节点');
        $ordered = $rows;
        usort($ordered, fn ($a, $b) => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);
        $locked = [];
        foreach ($ordered as $row) {
            abort_unless(in_array($row['field'], ['name', 'sort', 'entry_host', 'show'], true), 422, '不允许修改此字段');
            $server = app(ServerService::class)->getServer($row['id'], $row['type']);
            abort_unless($server, 409, $row['key'] . ' 已删除，请重新预览');
            $server = $server->newQuery()->whereKey($server->id)->lockForUpdate()->first();
            abort_unless($server, 409, $row['key'] . ' 已删除，请重新预览');
            $metadata = null;
            if ($row['field'] === 'entry_host') {
                $metadata = ServerMetadata::where('server_type', $row['type'])->where('server_id', $row['id'])->lockForUpdate()->first();
                abort_unless(($metadata->entry_host ?? null) === $row['expected'], 409, $row['key'] . ' 的入口已被修改，未覆盖任何后续改动');
                if (isset($row['metadata_id'])) abort_unless($metadata && (int) $metadata->id === $row['metadata_id'], 409, $row['key'] . ' 的入口记录已改变');
                if (isset($row['entry_machine_id'])) abort_unless($metadata && (int) $metadata->entry_machine_id === $row['entry_machine_id'], 409, $row['key'] . ' 的入口机器绑定已改变');
            } else {
                $actual = $row['field'] === 'show' ? (int) (bool) $server->show : $server->{$row['field']};
                abort_unless((string) $actual === (string) $row['expected'], 409, $row['key'] . ' 的字段已被修改，未覆盖任何后续改动');
            }
            $locked[] = [$row, $server, $metadata];
        }
        return $locked;
    }

    public function apply(array $rows): int
    {
        $count = DB::transaction(function () use ($rows) {
            foreach ($this->lockedRows($rows) as [$row, $server, $metadata]) {
                if ($row['field'] === 'entry_host') {
                    ServerMetadata::updateOrCreate(['server_type' => $row['type'], 'server_id' => $row['id']], ['entry_host' => $row['after']]);
                } else {
                    $server->{$row['field']} = $row['after'];
                    $server->save();
                }
            }
            return count($rows);
        });
        DB::afterCommit(function () use ($rows) {
            foreach (array_unique(array_column($rows, 'type')) as $type) Cache::forget('servers_' . $type);
        });
        return $count;
    }
}
