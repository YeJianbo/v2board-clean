<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GiftcardGenerate;
use App\Models\Giftcard;
use App\Services\GiftcardWriteService;
use App\Utils\Helper;
use App\Utils\Csv;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class GiftcardController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'sort' => 'sometimes|string|in:id,name,code,type,value,plan_id,limit_use,started_at,ended_at,created_at,updated_at',
            'sort_type' => 'sometimes|nullable|string|in:ASC,DESC',
        ]);
        $current = $request->input('current', 1);
        $pageSize = max($request->input('pageSize', 10), 10);
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort', 'id');
        
        $builder = Giftcard::orderBy($sort, $sortType)->orderBy('id', $sortType);
        $total = $builder->count();
        $giftcards = ($current - 1) * $pageSize >= $total
            ? collect()
            : $builder->forPage($current, $pageSize)->get();

        return response([
            'data' => $giftcards,
            'total' => $total
        ]);
    }

    public function generate(GiftcardGenerate $request)
    {
        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }

        $params = $request->validated();
        unset($params['generate_count'], $params['id']);
        $id = (int) $request->input('id') ?: null;
        try {
            $writer = app(GiftcardWriteService::class);
            if ($id === null) {
                if (!isset($params['code'])) $params['code'] = Helper::randomChar(16);
                $writer->create($params);
            } else {
                $writer->update($id, static function (Giftcard $giftcard) use ($params) {
                    $giftcard->fill($params);
                });
            }
        } catch (ModelNotFoundException $exception) {
            abort(404, '礼品卡不存在');
        } catch (\Throwable $exception) {
            $this->logWriteFailure($id === null ? 'create' : 'update', $id, $exception);
            abort(500, $id === null ? '礼品卡创建失败' : '礼品卡保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function multiGenerate(GiftcardGenerate $request)
    {
        $giftcards = [];
        $giftcard = $request->validated();
        $giftcard['created_at'] = $giftcard['updated_at'] = time();
        unset($giftcard['generate_count'], $giftcard['id']);
        
        $count = (int) $request->input('generate_count');
        $codes = [];
        while (count($codes) < $count) {
            while (count($codes) < $count) {
                $code = Helper::randomChar(16);
                $codes[strtolower($code)] = $code;
            }
            foreach (Giftcard::whereIn('code', array_values($codes))->pluck('code') as $existingCode) {
                unset($codes[strtolower($existingCode)]);
            }
        }
        foreach ($codes as $code) $giftcards[] = array_merge($giftcard, ['code' => $code]);
        try {
            DB::transaction(static function () use ($giftcards) {
                if (!Giftcard::insert($giftcards)) throw new \RuntimeException('礼品卡批量生成失败');
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('generate', null, $exception);
            abort(500, '礼品卡批量生成失败');
        }
        return Csv::download('giftcards.csv', static function ($stream) use ($giftcards): void {
            Csv::writeRow($stream, ['名称', '类型', '数值', '开始时间', '结束时间', '可用次数', '礼品卡卡密', '生成时间']);
            foreach ($giftcards as $giftcard) {
                $giftcardvalue = $giftcard['value'] ?? 0;
                $type = ['', '金额', '时长', '流量', '重置', '套餐'][$giftcard['type']];
                $value = ['', round($giftcardvalue/100, 2), $giftcardvalue . '天', $giftcardvalue . 'GB', '-', $giftcardvalue . '天'][$giftcard['type']];
                $startTime = date('Y-m-d H:i:s', $giftcard['started_at']);
                $endTime = date('Y-m-d H:i:s', $giftcard['ended_at']);
                $limitUse = $giftcard['limit_use'] ?? '不限制';
                $createTime = date('Y-m-d H:i:s', $giftcard['created_at']);
                Csv::writeRow($stream, [Csv::text($giftcard['name']), $type, $value, $startTime, $endTime,
                    $limitUse, Csv::text($giftcard['code']), $createTime]);
            }
        });
    }

    public function drop(Request $request)
    {
        $giftcardId = $request->input('id');
        if (empty($giftcardId)) {
            abort(400, '未找到礼品卡');
        }

        $params = $request->validate(['id' => 'required|integer|min:1']);
        try {
            $result = DB::transaction(static function () use ($params) {
                $giftcard = Giftcard::whereKey($params['id'])->lockForUpdate()->first();
                if (!$giftcard) return null;
                if (!$giftcard->delete()) throw new \RuntimeException('Gift card delete rejected.');
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', (int) $params['id'], $exception);
            abort(500, '删除失败');
        }
        if ($result === null) abort(404, '礼品卡不存在');

        return response([
            'data' => true
        ]);
    }

    private function logWriteFailure(string $action, ?int $id, \Throwable $exception): void
    {
        \Log::warning('Legacy gift card write failed', ['action' => $action, 'giftcard_id' => $id, 'exception' => $exception]);
    }
}
