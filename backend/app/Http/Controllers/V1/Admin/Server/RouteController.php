<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RouteController extends Controller
{
    public function fetch(Request $request)
    {
        $routes = ServerRoute::get();
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array)) $routes[$k]['match'] = $array;
        }
        return [
            'data' => $routes
        ];
    }

    public function save(Request $request)
    {
        if (is_string($request->input('remarks'))) $request->merge(['remarks' => trim($request->input('remarks'))]);
        $params = $request->validate([
            'id' => 'nullable|integer|min:0|max:2147483647',
            'remarks' => 'required|string|max:255',
            'match' => 'array|required_unless:action,default_out',
            'action' => 'required|in:block,block_ip,block_port,protocol,dns,route,route_ip,default_out',
            'action_value' => 'nullable'
        ], [
            'remarks.required' => '备注不能为空',
            'match.required_unless' => '匹配值不能为空',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误'
        ]);
        if (($params['action'] ?? '') === 'default_out') {
            $normalizedMatch = [];
        } else {
            $normalizedMatch = array_values(array_filter((array)($params['match'] ?? []), static fn ($value) => $value === '0' || (bool) $value));
            if (!$normalizedMatch) throw ValidationException::withMessages(['match' => '匹配值不能为空']);
        }
        try {
            $params['match'] = json_encode($normalizedMatch, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ValidationException::withMessages(['match' => '匹配值无法编码为有效 JSON，请检查字符编码和数值']);
        }
        $id = $params['id'] ?? null;
        unset($params['id']);
        try {
            $result = DB::transaction(static function () use ($id, $params) {
                $route = $id ? ServerRoute::whereKey($id)->lockForUpdate()->first() : new ServerRoute();
                if (!$route) return null;
                $route->fill($params);
                if (!$route->save()) throw new \RuntimeException('Route save was rejected.');
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $exception);
            abort(500, $id ? '保存失败' : '创建失败');
        }
        if ($result === null) abort(500, '路由不存在');
        return [
            'data' => true
        ];
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            $result = DB::transaction(static function () use ($params) {
                $route = ServerRoute::whereKey($params['id'])->lockForUpdate()->first();
                if (!$route) return null;
                if (!$route->delete()) throw new \RuntimeException('Route delete was rejected.');
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', $exception);
            abort(500, '删除失败');
        }
        if ($result === null) abort(500, '路由不存在');
        return [
            'data' => true
        ];
    }

    private function logWriteFailure(string $action, \Throwable $exception): void
    {
        Log::warning('Route write failed', ['action' => $action, 'exception_type' => get_class($exception)]);
    }
}
