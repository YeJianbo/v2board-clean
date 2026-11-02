<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Exceptions\GroupInUseException;
use App\Models\ServerGroup;
use App\Services\ServerGroupUsage;
use App\Services\ServerGroupWriteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('group_id')) {
            return response([
                'data' => [ServerGroup::find($request->input('group_id'))]
            ]);
        }
        $serverGroups = ServerGroup::withCount('users as user_count')->get();
        $counts = $serverGroups->isEmpty() ? [] : app(ServerGroupUsage::class)->counts();
        foreach ($serverGroups as $k => $v) {
            $serverGroups[$k]['server_count'] = $counts[$v['id']] ?? 0;
        }
        return response([
            'data' => $serverGroups
        ]);
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
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
            if (!$serverGroup) {
                abort(404, '组不存在');
            }
        }

        try {
            return response(['data' => $serverGroup->delete()]);
        } catch (GroupInUseException $exception) {
            abort($exception->kind === 'nodes' ? 422 : 500, $exception->getMessage());
        }
    }
}
