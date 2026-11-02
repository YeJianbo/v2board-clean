<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicStatusController;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MachineMonitorController extends Controller
{
    public static function allowedMachineIds(array $nodes, int $groupId): array
    {
        if ($groupId <= 0) return [];
        $byId = array_column($nodes, null, 'id');
        $ids = [];
        foreach ($nodes as $node) {
            $groupIds = $node['group_id'] ?? [];
            if (empty($node['show']) || !is_array($groupIds) || !in_array($groupId, $groupIds)) continue;
            $parent = $byId[$node['parent_id'] ?? 0] ?? [];
            foreach (['machine_id', 'relay_machine_id', 'entry_machine_id'] as $field) {
                $id = (int) ($node[$field] ?? 0);
                if ($field === 'machine_id' && !$id) $id = (int) ($parent[$field] ?? 0);
                if ($id > 0) $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public function fetch(Request $request)
    {
        $user = User::find($request->input('user.id'), ['id', 'banned', 'group_id', 'transfer_enable', 'expired_at']);
        abort_unless($user && !$user->banned, 403);
        $ids = [];
        $groupId = (int) $user->group_id;
        if ($groupId > 0 && (new UserService())->isAvailable($user)) {
            foreach ($this->monitorNodes() as $nodes) {
                $ids = array_merge($ids, self::allowedMachineIds($nodes, $groupId));
            }
        }
        $visible = app(PublicStatusController::class)->dataForMachineIds($ids);
        $fields = array_flip(['id', 'name', 'country', 'country_code', 'is_online', 'cpu', 'mem', 'system', 'load', 'net_in', 'net_out']);
        $visible = array_map(fn ($row) => array_intersect_key($row, $fields), $visible);
        return response()->json(['data' => $visible])->header('Cache-Control', 'private, no-store');
    }

    private function monitorNodes(): array
    {
        $union = null;
        foreach (['ServerShadowsocks', 'ServerVmess', 'ServerTrojan', 'ServerTuic',
            'ServerHysteria', 'ServerVless', 'ServerAnytls', 'ServerV2node'] as $name) {
            $class = 'App\\Models\\' . $name;
            $table = (new $class())->getTable();
            $type = strtolower(substr($name, 6));
            $query = $class::query()->leftJoin('v2_server_metadata as metadata', function ($join) use ($table, $type) {
                $join->on('metadata.server_id', '=', $table . '.id')->where('metadata.server_type', $type);
            })->select([
                $table . '.id', $table . '.show', $table . '.group_id', $table . '.parent_id',
                // Legacy AnyTLS has no machine_id column.
                $type === 'anytls' ? DB::raw('NULL AS machine_id') : $table . '.machine_id',
                $type === 'v2node' ? $table . '.relay_machine_id' : DB::raw('NULL AS relay_machine_id'),
                'metadata.entry_machine_id',
            ])->selectRaw('? AS server_type', [$type])->toBase();
            $union = $union === null ? $query : $union->unionAll($query);
        }

        $byType = [];
        foreach ($union->get() as $record) {
            $node = (array) $record;
            $node['group_id'] = json_decode((string) ($node['group_id'] ?? ''), true);
            // IDs and parent IDs are only unique within their protocol table.
            $byType[$node['server_type']][] = $node;
        }
        return $byType;
    }
}
