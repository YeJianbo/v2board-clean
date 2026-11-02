<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerAnytls;
use Illuminate\Http\Request;

class AnyTLSController extends Controller
{
    public function save(Request $request)
    {
        $metadata = $this->validateServerSaveRequest($request);
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1',
            'padding_scheme' => 'nullable',
        ]);
        $params = $this->serverSaveParameters($params);

        if (isset($params['padding_scheme'])) {
            $params['padding_scheme'] = json_decode($params['padding_scheme']);
        }

        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server = $this->saveServerWithMetadata($server, $params, 'anytls', $metadata);
            } catch (\Throwable $e) {
                \Log::error($e);
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        try {
            $server = $this->saveServerWithMetadata(new ServerAnytls(), $params, 'anytls', $metadata);
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        try {
            $this->deleteServerWithMetadata($server, 'anytls');
            $deleted = true;
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '删除失败');
        }
        return response(['data' => $deleted]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1'
        ], [
            'show.in' => '显示状态格式不正确'
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerAnytls::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server = $this->saveServerWithMetadata($server, $params, 'anytls', null);
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $server = ServerAnytls::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }
        try {
            $copiedServer = $this->copyServerWithMetadata($server, 'anytls', ['show' => 0], true);
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
