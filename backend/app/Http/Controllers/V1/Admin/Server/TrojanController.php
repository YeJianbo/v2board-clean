<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerTrojanSave;
use App\Http\Requests\Admin\ServerTrojanUpdate;
use App\Models\ServerTrojan;
use App\Services\ServerService;
use Illuminate\Http\Request;

class TrojanController extends Controller
{
    public function save(ServerTrojanSave $request)
    {
        $metadata = $this->validateServerSaveRequest($request);
        $params = $this->serverSaveParameters($request->validated());
        if ($request->input('id')) {
            $server = ServerTrojan::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server = $this->saveServerWithMetadata($server, $params, 'trojan', $metadata);
            } catch (\Throwable $e) {
                \Log::error($e);
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        try {
            $server = $this->saveServerWithMetadata(new ServerTrojan(), $params, 'trojan', $metadata);
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
            $server = ServerTrojan::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        try {
            $this->deleteServerWithMetadata($server, 'trojan');
            $deleted = true;
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '删除失败');
        }
        return response(['data' => $deleted]);
    }

    public function update(ServerTrojanUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerTrojan::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server = $this->saveServerWithMetadata($server, $params, 'trojan', null);
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
        $server = ServerTrojan::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }
        try {
            $copiedServer = $this->copyServerWithMetadata($server, 'trojan', ['show' => 0], true);
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
