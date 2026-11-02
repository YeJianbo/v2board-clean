<?php

namespace App\Services;

use App\Models\ServerV2node;
use Illuminate\Support\Facades\DB;

class DomainTrafficPolicy
{
    public function enabled(int $nodeId, ?int $machineId = null): bool
    {
        if (!admin_setting('domain_traffic_enable', true)) return false;
        if ($machineId === null) {
            $machineId = (int) ServerV2node::whereKey($nodeId)->value('machine_id');
        }
        $settings = DB::table('v2_domain_traffic_setting')
            ->where(function ($query) use ($nodeId, $machineId) {
                $query->where(fn ($q) => $q->where('scope', 'node')->where('target_id', $nodeId));
                if ($machineId > 0) $query->orWhere(fn ($q) => $q->where('scope', 'machine')->where('target_id', $machineId));
            })->get()->keyBy('scope');
        return (bool) ($settings['machine']->enabled ?? true) && (bool) ($settings['node']->enabled ?? true);
    }

    public function set(string $scope, int $id, bool $enabled): void
    {
        if (!in_array($scope, ['machine', 'node'], true) || $id < 1) {
            throw new \InvalidArgumentException('Invalid domain traffic collection target');
        }
        DB::table('v2_domain_traffic_setting')->updateOrInsert(
            ['scope' => $scope, 'target_id' => $id], ['enabled' => $enabled]
        );
    }
}
