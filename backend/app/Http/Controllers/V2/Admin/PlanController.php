<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Exceptions\PlanInUseException;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Services\BatchSortService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate(['options' => 'sometimes|boolean']);
        if (!empty($params['options'])) {
            return $this->success(Plan::orderBy('sort', 'ASC')->orderBy('id', 'ASC')->get([
                'id', 'name', 'month_price', 'quarter_price', 'half_year_price', 'year_price',
                'two_year_price', 'three_year_price', 'onetime_price', 'reset_price',
            ]));
        }
        $plans = Plan::orderBy('sort', 'ASC')
            ->with('group:id,name')
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        $id = $params['id'] ?? null;
        $forceUpdate = (bool) ($params['force_update'] ?? false);
        unset($params['id'], $params['force_update']);
        try {
            return DB::transaction(function () use ($id, $params, $forceUpdate) {
                $plan = $id ? Plan::whereKey($id)->lockForUpdate()->first() : new Plan();
                if (!$plan) return $this->fail([400202, '该订阅不存在']);
                if (!ServerGroup::whereKey($params['group_id'])->exists()) {
                    return $this->fail([400202, '权限组不存在']);
                }
                $existing = $plan->exists;
                $plan->fill($params);
                if (!$plan->save()) throw new \RuntimeException('Plan save was rejected.');
                if ($existing && $forceUpdate) {
                    // Omitted optional limits retain the plan's current values; explicit null clears them.
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $plan->group_id,
                        'transfer_enable' => $plan->transfer_enable * 1073741824,
                        'speed_limit' => $plan->speed_limit,
                        'device_limit' => $plan->device_limit,
                    ]);
                }
                return $this->success(true);
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $exception);
            return $this->fail([500, $id ? '保存失败' : '创建失败']);
        }
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            $plan = Plan::find($params['id']);
            if (!$plan) return $this->fail([400202, '该订阅不存在']);
            if (!$plan->delete()) return $this->fail([500, '删除失败']);
        } catch (PlanInUseException $exception) {
            return $this->fail([400201, $exception->getMessage()]);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', $exception);
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1|max:2147483647',
            'show' => 'sometimes|boolean',
            'renew' => 'sometimes|boolean',
        ]);
        $id = $params['id'];
        unset($params['id']);
        if (!$params) return $this->fail([422, '请指定展示或续费状态']);
        try {
            $result = DB::transaction(static function () use ($id, $params) {
                $plan = Plan::whereKey($id)->lockForUpdate()->first();
                if (!$plan) return null;
                $plan->fill($params);
                if (!$plan->save()) throw new \RuntimeException('Plan state save was rejected.');
                return true;
            }, 3);
            if ($result === null) return $this->fail([400202, '该订阅不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('update', $exception);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    private function logWriteFailure(string $action, \Throwable $exception): void
    {
        Log::warning('Plan write failed', ['action' => $action, 'exception_type' => get_class($exception), 'exception' => $exception]);
    }

    public function sort(Request $request)
    {
        $sorter = app(BatchSortService::class);
        $ids = $sorter->validatedIds($request);

        try {
            $sorter->save(new Plan(), $ids);
        } catch (\Throwable $e) {
            $this->logWriteFailure('sort', $e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
