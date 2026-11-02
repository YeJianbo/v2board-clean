<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Http\Requests\Admin\PlanSort;
use App\Http\Requests\Admin\PlanUpdate;
use App\Models\Plan;
use App\Services\PlanService;
use App\Services\BatchSortService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $counts = PlanService::countActiveUsers();
        $plans = Plan::orderBy('sort', 'ASC')->get();
        foreach ($plans as $plan) {
            $plan->count = (int) ($counts->get($plan->id)->count ?? 0);
        }
        return response([
            'data' => $plans
        ]);
    }

    public function save(PlanSave $request)
    {
        $response = (new \App\Http\Controllers\V2\Admin\PlanController())->save($request);
        if ($response->getStatusCode() !== 200) abort(500, $response->getData(true)['message']);
        return response(['data' => true]);
    }

    public function drop(Request $request)
    {
        $response = (new \App\Http\Controllers\V2\Admin\PlanController())->drop($request);
        if ($response->getStatusCode() !== 200) abort(500, $response->getData(true)['message']);
        return response(['data' => true]);
    }

    public function update(PlanUpdate $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        $id = $params['id'];
        $updateData = $request->only([
            'show',
            'renew'
        ]);

        try {
            $result = DB::transaction(static function () use ($id, $updateData) {
                $plan = Plan::whereKey($id)->lockForUpdate()->first();
                if (!$plan) return null;
                if (!$plan->update($updateData)) {
                    throw new \RuntimeException('Plan state save was rejected.');
                }
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            Log::warning('Legacy plan state write failed', ['exception' => $exception]);
            abort(500, '保存失败');
        }
        if ($result === null) abort(500, '该订阅不存在');

        return response([
            'data' => true
        ]);
    }

    public function sort(PlanSort $request)
    {
        $sorter = app(BatchSortService::class);
        $ids = $sorter->validatedIds($request, 'plan_ids');
        try {
            $sorter->save(new Plan(), $ids);
        } catch (\Throwable $error) {
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }
}
