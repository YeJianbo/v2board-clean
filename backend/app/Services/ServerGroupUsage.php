<?php

namespace App\Services;

use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;

class ServerGroupUsage
{
    private const MODELS = [ServerShadowsocks::class, ServerVmess::class, ServerTrojan::class,
        ServerTuic::class, ServerHysteria::class, ServerVless::class, ServerAnytls::class, ServerV2node::class];

    public function counts(): array
    {
        $counts = [];
        $query = null;
        foreach (self::MODELS as $model) {
            $memberships = $model::query()->select('group_id')->toBase();
            // Identical memberships still represent separate nodes, so retain duplicate rows.
            $query = $query ? $query->unionAll($memberships) : $memberships;
        }
        foreach ($query->cursor() as $node) {
            $groups = json_decode((string) $node->group_id, true);
            foreach (array_unique(array_map('intval', (array) $groups)) as $id) {
                if ($id > 0) $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        return $counts;
    }

    public function contains(int $groupId, ?string $connectionName = null, bool $lock = false): bool
    {
        foreach (self::MODELS as $model) {
            $node = new $model();
            $node->setConnection($connectionName);
            $query = $node->newQuery()->select('group_id');
            if ($lock) $query->sharedLock();
            // Raw projections retain legacy numeric/string memberships without hydrating node status.
            foreach ($query->toBase()->cursor() as $row) {
                $groups = json_decode((string) $row->group_id, true);
                if (in_array($groupId, array_map('intval', (array) $groups), true)) return true;
            }
        }
        return false;
    }
}
