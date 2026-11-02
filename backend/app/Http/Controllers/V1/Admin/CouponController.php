<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponGenerate;
use App\Models\Coupon;
use App\Services\CouponMutationService;
use App\Utils\Helper;
use App\Utils\Csv;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'sort' => 'sometimes|nullable|string|in:id,name,code,type,value,show,limit_use,limit_use_with_user,limit_plan_ids,limit_period,started_at,ended_at,created_at,updated_at',
            'sort_type' => 'sometimes|nullable|string|in:ASC,DESC',
        ]);
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'id';
        $builder = Coupon::orderBy($sort, $sortType)->orderBy('id', $sortType);
        $total = $builder->count();
        $coupons = ($current - 1) * $pageSize >= $total
            ? collect()
            : $builder->forPage($current, $pageSize)->get();
        return response([
            'data' => $coupons,
            'total' => $total
        ]);
    }

    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(CouponMutationService::class)->toggle((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            abort(500, '优惠券不存在');
        } catch (\Throwable $exception) {
            \Log::error($exception);
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function generate(CouponGenerate $request)
    {
        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }

        $params = $request->validated();
        unset($params['generate_count']);
        $id = (int) $request->input('id') ?: null;
        try {
            app(CouponMutationService::class)->save($params, $id);
        } catch (ModelNotFoundException $exception) {
            abort(500, '优惠券不存在');
        } catch (\Throwable $exception) {
            \Log::error($exception);
            abort(500, $id ? '保存失败' : '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function multiGenerate(CouponGenerate $request)
    {
        $coupons = [];
        $coupon = $request->validated();
        $coupon['created_at'] = $coupon['updated_at'] = time();
        $coupon['show'] = 1;
        unset($coupon['generate_count'], $coupon['id']);
        for ($i = 0;$i < $request->input('generate_count');$i++) {
            $coupon['code'] = Helper::randomChar(8);
            array_push($coupons, $coupon);
        }
        DB::transaction(function () use ($coupons, $coupon) {
            if (!Coupon::insert(array_map(function ($item) use ($coupon) {
                if (isset($item['limit_plan_ids']) && is_array($item['limit_plan_ids'])) {
                    $item['limit_plan_ids'] = json_encode($coupon['limit_plan_ids']);
                }
                if (isset($item['limit_period']) && is_array($item['limit_period'])) {
                    $item['limit_period'] = json_encode($coupon['limit_period']);
                }
                return $item;
            }, $coupons))) {
                abort(500, '生成失败');
            }
        }, 3);
        return Csv::download('coupons.csv', static function ($stream) use ($coupons): void {
            Csv::writeRow($stream, ['名称', '类型', '金额或比例', '开始时间', '结束时间', '可用次数', '可用于订阅', '券码', '生成时间']);
            foreach ($coupons as $coupon) {
                $type = ['', '金额', '比例'][$coupon['type']];
                $value = ['', ($coupon['value'] / 100),$coupon['value']][$coupon['type']];
                $startTime = date('Y-m-d H:i:s', $coupon['started_at']);
                $endTime = date('Y-m-d H:i:s', $coupon['ended_at']);
                $limitUse = $coupon['limit_use'] ?? '不限制';
                $createTime = date('Y-m-d H:i:s', $coupon['created_at']);
                $limitPlanIds = isset($coupon['limit_plan_ids']) ? implode("/", $coupon['limit_plan_ids']) : '不限制';
                Csv::writeRow($stream, [Csv::text($coupon['name']), $type, $value, $startTime, $endTime,
                    $limitUse, Csv::text((string) $limitPlanIds), Csv::text($coupon['code']), $createTime]);
            }
        });
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(CouponMutationService::class)->drop((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            abort(500, '优惠券不存在');
        } catch (\Throwable $exception) {
            \Log::error($exception);
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }
}
