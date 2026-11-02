<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\ServerRoute;
use App\Models\ServerV2node;
use Illuminate\Support\Facades\DB;

class DomainTrafficBlockService
{
    public function options(): array
    {
        $counts = ServerV2node::query()->whereNotNull('machine_id')->where('protocol', '!=', 'ravel')
            ->selectRaw('machine_id, COUNT(*) as node_count')->groupBy('machine_id')->pluck('node_count', 'machine_id');
        return Machine::query()->orderBy('sort')->orderBy('id')->get(['id', 'name'])
            ->filter(fn ($machine) => ($counts[$machine->id] ?? 0) > 0)
            ->map(fn ($machine) => ['id' => (int) $machine->id, 'name' => $machine->name,
                'node_count' => (int) $counts[$machine->id]])->values()->all();
    }

    public function block(string $domain, array $machineIds): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($domain, '.')) {
            throw new \InvalidArgumentException('域名无效');
        }
        $machineIds = array_values(array_unique(array_map('intval', $machineIds)));
        if (!$machineIds || count($machineIds) > 100 || in_array(0, $machineIds, true)) {
            throw new \InvalidArgumentException('请选择有效机器');
        }
        $result = DB::transaction(function () use ($domain, $machineIds) {
            $nodes = ServerV2node::query()->whereIn('machine_id', $machineIds)
                ->where('protocol', '!=', 'ravel')->orderBy('id')->lockForUpdate()
                ->get(['id', 'machine_id', 'route_id']);
            if ($nodes->isEmpty() || count(array_unique($nodes->pluck('machine_id')->all())) !== count($machineIds)) {
                throw new \InvalidArgumentException('所选机器中存在没有可配置节点的机器');
            }
            $match = 'domain:' . $domain;
            $route = ServerRoute::query()->where('action', 'block')->select(['id', 'match'])->cursor()
                ->first(fn ($item) => json_decode((string) $item->match, true) === [$match]);
            if (!$route) {
                $route = ServerRoute::create(['remarks' => '网站流量屏蔽 ' . $domain,
                    'match' => json_encode([$match]), 'action' => 'block', 'action_value' => '']);
            }
            $changed = [];
            foreach ($nodes as $node) {
                $routes = array_map('intval', $node->route_id ?: []);
                if (in_array((int) $route->id, $routes, true)) continue;
                array_unshift($routes, (int) $route->id);
                $node->route_id = $routes;
                $node->save();
                $changed[] = (int) $node->id;
            }
            return ['route_id' => (int) $route->id, 'nodes_changed' => $changed,
                'machine_ids' => $machineIds];
        }, 3);
        foreach ($machineIds as $machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
        return $result;
    }
}
