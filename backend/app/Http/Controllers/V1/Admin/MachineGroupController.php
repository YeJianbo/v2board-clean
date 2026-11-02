<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MachineGroupController extends Controller
{
    public function fetch()
    {
        $groups = MachineGroup::query()
            ->withCount('machines')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (MachineGroup $group) {
                return [
                    'id' => (int) $group->id,
                    'name' => (string) $group->name,
                    'sort' => (int) $group->sort,
                    'machine_count' => (int) $group->machines_count,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ];
            })
            ->values();

        return response(['data' => $groups]);
    }

    public function save(Request $request)
    {
        $groupId = (int) $request->input('id', 0);
        $request->merge(['name' => trim((string) $request->input('name', ''))]);
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_machine_group,id',
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('v2_machine_group', 'name')->ignore($groupId ?: null),
            ],
        ]);
        $name = trim((string) $params['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => '分组名称不能为空']);
        }

        $group = DB::transaction(function () use ($groupId, $name) {
            if ($groupId > 0) {
                $group = MachineGroup::whereKey($groupId)->lockForUpdate()->firstOrFail();
                $group->fill(['name' => $name]);
            } else {
                $group = new MachineGroup([
                    'name' => $name,
                    'sort' => ((int) MachineGroup::max('sort')) + 1,
                ]);
            }
            if (!$group->save() || !$group->exists || !$group->getKey()) {
                throw new \RuntimeException('Machine group save rejected.');
            }
            return $group;
        });

        return response([
            'data' => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'sort' => (int) $group->sort,
            ],
        ]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_machine_group,id',
        ]);
        DB::transaction(function () use ($params) {
            $groupId = (int) $params['id'];
            $group = MachineGroup::whereKey($groupId)->lockForUpdate()->firstOrFail();
            Machine::query()
                ->where('machine_group_id', $groupId)
                ->update(['machine_group_id' => null]);
            if (!$group->delete()) throw new \RuntimeException('Machine group delete rejected.');
            // Deleting hooks can create bindings in this transaction after the first unlink.
            Machine::query()->where('machine_group_id', $groupId)->update(['machine_group_id' => null]);
        });

        return response(['data' => true]);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|distinct|exists:v2_machine_group,id',
        ]);

        DB::transaction(function () use ($params) {
            foreach (array_values($params['ids']) as $index => $groupId) {
                MachineGroup::query()
                    ->whereKey((int) $groupId)
                    ->update(['sort' => $index + 1]);
            }
        });

        return response(['data' => true]);
    }
}
