<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponGenerate;
use App\Models\Coupon;
use App\Services\CouponMutationService;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    private function applyFiltersAndSorts(Request $request, $builder)
    {
        if ($request->has('filter')) {
            collect($request->input('filter'))->each(function ($filter) use ($builder) {
                $key = $filter['id'];
                $value = $filter['value'];
                $builder->where(function ($query) use ($key, $value) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, 'like', "%{$value}%");
                    }
                });
            });
        }

        if ($request->has('sort')) {
            collect($request->input('sort'))->each(function ($sort) use ($builder) {
                $key = $sort['id'];
                $value = $sort['desc'] ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }
    public function fetch(Request $request)
    {
        $fields = 'id,name,code,type,value,show,limit_use,limit_use_with_user,limit_plan_ids,limit_period,started_at,ended_at,created_at,updated_at';
        $request->validate([
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'keyword' => 'sometimes|nullable|string|max:255',
            'filter' => 'sometimes|array|max:20',
            'filter.*' => 'array',
            'filter.*.id' => 'required|string|in:' . $fields,
            'filter.*.value' => ['present', function ($attribute, $value, $fail) {
                $values = is_array($value) ? $value : [$value];
                if (count($values) > 500) {
                    $fail('筛选列表最多包含500个值');
                    return;
                }
                foreach ($values as $item) {
                    if ((!is_null($item) && !is_scalar($item)) || (is_string($item) && mb_strlen($item) > 255)) {
                        $fail('筛选值必须是长度不超过255的文本、数字或它们组成的列表');
                        return;
                    }
                }
            }],
            'sort' => 'sometimes|array|max:10',
            'sort.*' => 'array',
            'sort.*.id' => 'required|string|in:' . $fields,
            'sort.*.desc' => 'required|boolean',
        ]);
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);
        $builder = Coupon::query();
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $builder->where(fn ($query) => $query->where('name', 'like', "%{$keyword}%")
                ->orWhere('code', 'like', "%{$keyword}%"));
        }
        $this->applyFiltersAndSorts($request, $builder);
        $total = (clone $builder)->toBase()->getCountForPagination();
        $coupons = ($current - 1) * $pageSize < $total
            ? $builder->orderBy('created_at', 'desc')->orderBy('id', 'desc')->forPage($current, $pageSize)->get()
            : $builder->getModel()->newCollection();
        return $this->paginate(new LengthAwarePaginator($coupons, $total, $pageSize, $current));
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1|max:2147483647',
            'show' => 'nullable|boolean'
        ], [
            'id.required' => '优惠券ID不能为空',
            'id.integer' => '优惠券ID必须为整数'
        ]);
        try {
            app(CouponMutationService::class)->save($params, (int) $params['id']);
        } catch (ModelNotFoundException $e) {
            return $this->fail([400201, '优惠券不存在']);
        } catch (\Throwable $e) {
            \Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function show(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1|max:2147483647'
        ], [
            'id.required' => '优惠券ID不能为空',
            'id.integer' => '优惠券ID必须为整数'
        ]);
        try {
            app(CouponMutationService::class)->toggle((int) $request->input('id'));
        } catch (ModelNotFoundException $exception) {
            return $this->fail([400202, '优惠券不存在']);
        } catch (\Throwable $exception) {
            \Log::error($exception);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
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
            return $this->fail([400202, '优惠券不存在']);
        } catch (\Throwable $exception) {
            \Log::error($exception);
            return $this->fail([500, $id ? '保存失败' : '创建失败']);
        }

        return $this->success(true);
    }

    private function multiGenerate(CouponGenerate $request)
    {
        $coupons = [];
        $coupon = $request->validated();
        $coupon['created_at'] = $coupon['updated_at'] = time();
        $coupon['show'] = 1;
        unset($coupon['generate_count'], $coupon['id']);
        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            $coupon['code'] = Helper::randomChar(8);
            array_push($coupons, $coupon);
        }
        try {
            DB::beginTransaction();
            if (
                !Coupon::insert(array_map(function ($item) use ($coupon) {
                    // format data
                    if (isset($item['limit_plan_ids']) && is_array($item['limit_plan_ids'])) {
                        $item['limit_plan_ids'] = json_encode($coupon['limit_plan_ids']);
                    }
                    if (isset($item['limit_period']) && is_array($item['limit_period'])) {
                        $item['limit_period'] = json_encode($coupon['limit_period']);
                    }
                    return $item;
                }, $coupons))
            ) {
                throw new \Exception();
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error($e);
            return $this->fail([500, '生成失败']);
        }

        return $this->success(['count' => count($coupons)]);
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1|max:2147483647'
        ], [
            'id.required' => '优惠券ID不能为空',
            'id.integer' => '优惠券ID必须为整数'
        ]);
        try {
            app(CouponMutationService::class)->drop((int) $request->input('id'));
        } catch (ModelNotFoundException $exception) {
            return $this->fail([400202, '优惠券不存在']);
        } catch (\Throwable $exception) {
            \Log::error($exception);
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }
}
