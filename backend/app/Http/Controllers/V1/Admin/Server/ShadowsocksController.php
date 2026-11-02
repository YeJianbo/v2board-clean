<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerShadowsocksSave;
use App\Http\Requests\Admin\ServerShadowsocksUpdate;
use App\Models\ServerShadowsocks;
use Illuminate\Http\Request;

class ShadowsocksController extends Controller
{
    public function save(ServerShadowsocksSave $request)
    {
        $metadata = $this->validateServerSaveRequest($request);
        $params = $this->serverSaveParameters($request->validated());
        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server = $this->saveServerWithMetadata($server, $params, 'shadowsocks', $metadata);
            } catch (\Throwable $e) {
                \Log::error($e);
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        try {
            $server = $this->saveServerWithMetadata(new ServerShadowsocks(), $params, 'shadowsocks', $metadata);
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
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        try {
            $this->deleteServerWithMetadata($server, 'shadowsocks');
            $deleted = true;
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '删除失败');
        }
        return response(['data' => $deleted]);
    }

    public function update(ServerShadowsocksUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerShadowsocks::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server = $this->saveServerWithMetadata($server, $params, 'shadowsocks', null);
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
        $server = ServerShadowsocks::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }
        try {
            $copiedServer = $this->copyServerWithMetadata($server, 'shadowsocks', ['show' => 0], true);
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
