<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerHysteria;
use App\Utils\Helper;
use Illuminate\Http\Request;

class HysteriaController extends Controller
{
    public function save(Request $request)
    {
        $metadata = $this->validateServerSaveRequest($request);
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'version' => 'required|in:1,2',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'up_mbps' => 'nullable|numeric',
            'down_mbps' => 'nullable|numeric',
            'obfs' => 'nullable',
            'obfs_password' => 'nullable',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1'
        ]);
        $params = $this->serverSaveParameters($params);

        if (!isset($params['up_mbps'])) {
            $params['up_mbps'] = 0;
        }
        if (!isset($params['down_mbps'])) {
            $params['down_mbps'] = 0;
        }

        if(isset($params['obfs'])) {
            if(!isset($params['obfs_password']))  $params['obfs_password'] = Helper::getServerKey($request->input('created_at'), 16);
        } else {
            $params['obfs_password'] = null;
        }

        if ($request->input('id')) {
            $server = ServerHysteria::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server = $this->saveServerWithMetadata($server, $params, 'hysteria', $metadata);
            } catch (\Throwable $e) {
                \Log::error($e);
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        try {
            $server = $this->saveServerWithMetadata(new ServerHysteria(), $params, 'hysteria', $metadata);
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
            $server = ServerHysteria::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        try {
            $this->deleteServerWithMetadata($server, 'hysteria');
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

        $server = ServerHysteria::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server = $this->saveServerWithMetadata($server, $params, 'hysteria', null);
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
        $server = ServerHysteria::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }
        try {
            $copiedServer = $this->copyServerWithMetadata($server, 'hysteria', ['show' => 0], true);
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
