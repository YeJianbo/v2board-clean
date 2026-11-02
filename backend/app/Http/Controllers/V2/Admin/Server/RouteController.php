<?php

namespace App\Http\Controllers\V2\Admin\Server;

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
        foreach ($routes as $route) {
            $match = $route->match;
            $route->match = is_array($match) ? $match : (json_decode((string) $match, true) ?: []);
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
            'match' => 'required_unless:action,default_out|array',
            'match.*' => 'string',
            'action' => 'required|in:block,block_ip,block_port,protocol,dns,route,route_ip,default_out,direct,proxy',
            'action_value' => [
                'bail', 'required_if:action,route,route_ip,default_out,dns', 'nullable', 'string',
                function ($attribute, $value, $fail) use ($request) {
                    if (!in_array($request->input('action'), ['route', 'route_ip', 'default_out'], true)) return;
                    $outbound = json_decode($value);
                    if (!is_object($outbound)) {
                        $fail('出站配置必须是 JSON 对象');
                        return;
                    }
                    foreach (['tag', 'protocol'] as $field) {
                        if (!isset($outbound->$field) || !is_string($outbound->$field) || trim($outbound->$field) === '') {
                            $fail("出站配置缺少有效的 {$field}");
                        }
                    }
                    if (str_contains(json_encode($outbound, JSON_UNESCAPED_UNICODE), '<请填写')) {
                        $fail('请先替换出站示例中的占位内容');
                    }
                },
            ]
        ], [
            'remarks.required' => '备注不能为空',
            'match.required' => '匹配值不能为空',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误',
            'action_value.required_if' => '请填写 DNS 地址或出站配置',
            'action_value.string' => '动作参数必须是文本'
        ]);
        $matches = array_values(array_filter($params['match'] ?? [], static fn ($value) => $value !== ''));
        if ($params['action'] !== 'default_out' && !$matches) {
            throw ValidationException::withMessages(['match' => '匹配值不能为空']);
        }
        try {
            $params['match'] = json_encode($matches, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
            return $this->fail([500, $id ? '保存失败' : '创建失败']);
        }
        if ($result === null) return $this->fail([400202, '路由不存在']);
        return $this->success(true);
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
            return $this->fail([500, '删除失败']);
        }
        if ($result === null) return $this->fail([400202, '路由不存在']);
        return $this->success(true);
    }

    private function logWriteFailure(string $action, \Throwable $exception): void
    {
        Log::warning('Route write failed', ['action' => $action, 'exception_type' => get_class($exception)]);
    }
}
