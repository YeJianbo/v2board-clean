<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Exceptions\GroupInUseException;
use App\Services\ServerGroupUsage;
use App\Services\ServerGroupWriteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\ServerGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GroupController extends Controller
{
    public function fetch(Request $request): JsonResponse
    {
        $params = $request->validate(['options' => 'sometimes|boolean']);
        if (!empty($params['options'])) {
            return $this->success(ServerGroup::orderByDesc('id')->get(['id', 'name']));
        }
        $serverGroups = ServerGroup::query()
            ->orderByDesc('id')
            ->withCount('users')
            ->get();

        $counts = $serverGroups->isEmpty() ? [] : app(ServerGroupUsage::class)->counts();
        $serverGroups->each(function ($group) use ($counts) {
            $group->setAttribute('server_count', $counts[$group->id] ?? 0);
        });

        return $this->success($serverGroups);
    }

    public function save(Request $request)
    {
        if (is_string($request->input('name'))) $request->merge(['name' => trim($request->input('name'))]);
        $data = $request->validate(['name' => 'required|string|max:255', 'id' => 'nullable|integer|min:1']);

        $id = isset($data['id']) ? (int) $data['id'] : null;
        try {
            app(ServerGroupWriteService::class)->save($data['name'], $id);
        } catch (ModelNotFoundException $exception) {
            abort(404, '组不存在');
        } catch (\Throwable $exception) {
            \Log::warning('Server group save failed', ['group_id' => $id, 'exception' => $exception]);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $groupId = $request->input('id');

        $serverGroup = ServerGroup::find($groupId);
        if (!$serverGroup) {
            return $this->fail([400202, '组不存在']);
        }
        try {
            return $this->success($serverGroup->delete());
        } catch (GroupInUseException $exception) {
            return $this->fail([400, $exception->getMessage()]);
        }
    }
}
