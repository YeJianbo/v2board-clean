<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Giftcard;
use App\Models\GiftcardUsage;
use App\Services\GiftcardWriteService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GiftCardController extends Controller
{
    private function formatGiftcard(Giftcard $giftcard): array
    {
        $usedUserIds = (array)($giftcard->used_user_ids ?: []);
        $plan = $giftcard->relationLoaded('plan') ? $giftcard->plan : null;
        $type = (int)$giftcard->type;
        $rootUsed = $this->isUsed($giftcard) ? 1 : 0;
        $codesCount = isset($giftcard->codes_count) ? 1 + (int)$giftcard->codes_count : 1;
        $usedCount = isset($giftcard->used_codes_count)
            ? $rootUsed + (int)$giftcard->used_codes_count
            : $rootUsed;

        return [
            'id' => (int)$giftcard->id,
            'template_id' => (int)($giftcard->template_id ?: $giftcard->id),
            'name' => (string)$giftcard->name,
            'plan_id' => $giftcard->plan_id ? (int)$giftcard->plan_id : null,
            'plan_name' => $plan?->name ?? '',
            'period' => $this->formatPeriod($giftcard),
            'price' => (int)($type === 1 ? $giftcard->value : 0),
            'code' => (string)$giftcard->code,
            'status' => $this->isAvailable($giftcard) ? 0 : 1,
            'availability_reason' => $this->availabilityReason($giftcard),
            'enabled' => (bool)$giftcard->enabled,
            'used_at' => $giftcard->used_at ? (int)$giftcard->used_at : null,
            'used_by_user_id' => $giftcard->used_by_user_id
                ? (int)$giftcard->used_by_user_id
                : ($usedUserIds[0] ?? null),
            'codes_count' => $codesCount,
            'used_count' => $usedCount,
            'created_at' => (int)$giftcard->created_at,
            'updated_at' => (int)$giftcard->updated_at,
        ];
    }

    private function isUsed(Giftcard $giftcard): bool
    {
        return (int)($giftcard->used_at ?? 0) > 0
            || ($giftcard->limit_use !== null && (int)$giftcard->limit_use <= 0);
    }

    private function isAvailable(Giftcard $giftcard): bool
    {
        return $this->availabilityReason($giftcard) === 'available';
    }

    private function availabilityReason(Giftcard $giftcard): string
    {
        $now = time();
        if ($giftcard->limit_use !== null && (int)$giftcard->limit_use <= 0) return 'used';
        if (!(bool)$giftcard->enabled) return 'disabled';
        if ((int)$giftcard->started_at && (int)$giftcard->started_at > $now) return 'not_started';
        if ((int)$giftcard->ended_at && (int)$giftcard->ended_at < $now) return 'expired';
        return 'available';
    }

    private function formatPeriod(Giftcard $giftcard): string
    {
        $type = (int)$giftcard->type;
        if ($type === 2 || $type === 5) {
            return ((int)$giftcard->value) . '天';
        }
        if ($type === 3) {
            return ((int)$giftcard->value) . 'GB';
        }
        if ($type === 4) {
            return '重置';
        }
        return '金额';
    }

    private function periodDays(?string $period): int
    {
        return match ((string)$period) {
            'month_price' => 30,
            'quarter_price' => 90,
            'half_year_price' => 180,
            'year_price' => 365,
            'two_year_price' => 730,
            'three_year_price' => 1095,
            default => 0,
        };
    }

    private function giftcardCopyAttributes(Giftcard $source, int $now): array
    {
        return [
            'template_id' => $source->template_id ?: $source->id,
            'code' => Helper::randomChar(16),
            'name' => $source->name,
            'type' => $source->type,
            'value' => $source->value,
            'plan_id' => $source->plan_id,
            'limit_use' => $source->limit_use === null ? null : 1,
            'enabled' => true,
            'used_user_ids' => null,
            'used_at' => null,
            'used_by_user_id' => null,
            'started_at' => $source->started_at ?: $now,
            'ended_at' => $source->ended_at ?: strtotime('+10 years', $now),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function templates(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:1000',
            'pageSize' => 'sometimes|integer|min:1|max:1000',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'current' => 'sometimes|integer|min:1|max:1000000',
        ]);
        $pageSize = (int)$request->input('per_page', $request->input('pageSize', 15));
        $current = (int)$request->input('page', $request->input('current', 1));
        $query = Giftcard::query()
            ->whereNull('template_id')
            ->with('plan:id,name')
            ->withCount('codes')
            ->withCount([
                'codes as used_codes_count' => static function ($query) {
                    $query->where(static function ($used) {
                        $used->whereNotNull('used_at')->orWhere('limit_use', '<=', 0);
                    });
                },
            ])
            ->orderBy('id', 'desc');
        $total = (clone $query)->toBase()->getCountForPagination();
        $items = ($current - 1) * $pageSize < $total
            ? $query->forPage($current, $pageSize)->get()
            : $query->getModel()->newCollection();
        $templates = new LengthAwarePaginator($items, $total, $pageSize, $current);

        $templates->getCollection()->transform(fn (Giftcard $giftcard) => $this->formatGiftcard($giftcard));
        return $this->paginate($templates);
    }

    public function createTemplate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'plan_id' => 'nullable|integer|exists:v2_plan,id',
            'period' => 'nullable|string|max:64',
            'price' => 'nullable|integer|min:0|max:2147483647',
        ]);

        $planId = $request->input('plan_id');
        $giftcard = app(GiftcardWriteService::class)->create([
            'template_id' => null,
            'code' => Helper::randomChar(16),
            'name' => $request->input('name'),
            'type' => $planId ? 5 : 1,
            'value' => $planId
                ? $this->periodDays($request->input('period'))
                : (int)$request->input('price', 0),
            'plan_id' => $planId,
            'limit_use' => 1,
            'enabled' => true,
            'used_user_ids' => null,
            'used_at' => null,
            'used_by_user_id' => null,
            'started_at' => time(),
            'ended_at' => strtotime('+10 years'),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $giftcard->load('plan:id,name');
        return $this->success($this->formatGiftcard($giftcard));
    }

    public function generateCodes(Request $request)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:v2_giftcard,id',
            'count' => 'required|integer|min:1|max:10000',
        ]);

        $count = (int)$request->input('count', 1);
        DB::transaction(function () use ($request, $count) {
            $source = Giftcard::whereNull('template_id')->lockForUpdate()->findOrFail($request->input('template_id'));
            $now = time();
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $rows[] = $this->giftcardCopyAttributes($source, $now);
                if (count($rows) === 500) {
                    if (!Giftcard::insert($rows)) throw new \RuntimeException('Gift card batch insert failed.');
                    $rows = [];
                }
            }
            if ($rows && !Giftcard::insert($rows)) throw new \RuntimeException('Gift card batch insert failed.');
        }, 3);

        return $this->success(['count' => $count, 'message' => '生成成功']);
    }

    public function codes(Request $request)
    {
        $request->validate([
            'template_id' => 'sometimes|nullable|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:500',
            'page_size' => 'sometimes|integer|min:1|max:500',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'current' => 'sometimes|integer|min:1|max:1000000',
        ]);
        $pageSize = (int)$request->input('per_page', $request->input('page_size', $request->input('pageSize', 15)));
        $current = (int)$request->input('page', $request->input('current', 1));
        $query = Giftcard::query()->with('plan:id,name')->orderBy('id', 'desc');

        if ($request->filled('template_id')) {
            $templateId = (int)$request->input('template_id');
            $query->where(static function ($codes) use ($templateId) {
                $codes->where('id', $templateId)->orWhere('template_id', $templateId);
            });
        }

        $total = (clone $query)->toBase()->getCountForPagination();
        $items = ($current - 1) * $pageSize < $total
            ? $query->forPage($current, $pageSize)->get()
            : $query->getModel()->newCollection();
        $codes = new LengthAwarePaginator($items, $total, $pageSize, $current);
        $codes->getCollection()->transform(fn (Giftcard $giftcard) => $this->formatGiftcard($giftcard));
        return $this->paginate($codes);
    }

    public function toggleCode(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_giftcard,id']);
        $giftcard = app(GiftcardWriteService::class)->update((int) $request->input('id'), static function (Giftcard $giftcard) {
            $giftcard->enabled = !$giftcard->enabled;
        });
        return $this->success(['enabled' => (bool)$giftcard->enabled]);
    }

    public function exportCodes(Request $request)
    {
        $request->validate(['template_id' => 'sometimes|nullable|integer|min:1']);
        $query = Giftcard::query()->select(['id', 'code']);
        if ($request->filled('template_id')) {
            $templateId = (int)$request->input('template_id');
            $query->where(static function ($codes) use ($templateId) {
                $codes->where('id', $templateId)->orWhere('template_id', $templateId);
            });
        }

        $lastId = (int)(clone $query)->max('id');
        $query->where('id', '<=', $lastId);
        return response()->stream(function () use ($query) {
            $first = true;
            foreach ($query->lazyById(500) as $giftcard) {
                if (!$first) echo "\n";
                echo (string)$giftcard->code;
                $first = false;
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="gift_cards.txt"',
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function usages(Request $request)
    {
        $pageSize = max(1, min((int)$request->input('page_size', 20), 200));
        $query = GiftcardUsage::query()
            ->with(['giftcard:id,name,code', 'user:id,email'])
            ->orderByDesc('id');
        if ($request->filled('giftcard_id')) {
            $query->where('giftcard_id', (int)$request->input('giftcard_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int)$request->input('user_id'));
        }

        $usages = $query->paginate($pageSize);
        $usages->getCollection()->transform(static function (GiftcardUsage $usage) {
            return [
                'id' => (int)$usage->id,
                'giftcard_id' => (int)$usage->giftcard_id,
                'code' => (string)($usage->giftcard->code ?? ''),
                'name' => (string)($usage->giftcard->name ?? ''),
                'user_id' => (int)$usage->user_id,
                'user_email' => (string)($usage->user->email ?? ''),
                'type' => (int)$usage->type,
                'value' => $usage->value === null ? null : (int)$usage->value,
                'plan_id' => $usage->plan_id ? (int)$usage->plan_id : null,
                'ip' => (string)($usage->ip ?? ''),
                'created_at' => (int)$usage->created_at,
            ];
        });
        return $this->paginate($usages);
    }

    public function statistics(Request $request)
    {
        $totals = Giftcard::query()
            ->selectRaw('COUNT(*) as codes_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN template_id IS NULL THEN 1 ELSE 0 END), 0) as templates_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN template_id IS NULL AND enabled = 1 THEN 1 ELSE 0 END), 0) as active_templates_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN used_at IS NOT NULL THEN 1 ELSE 0 END), 0) as used_codes_count')
            ->first();
        $dailyUsages = GiftcardUsage::query()
            ->where('created_at', '>=', strtotime('-30 days'))
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        $typeStats = GiftcardUsage::query()
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->get();

        return $this->success([
            'total_stats' => [
                'templates_count' => (int) $totals->templates_count,
                'active_templates_count' => (int) $totals->active_templates_count,
                'codes_count' => (int) $totals->codes_count,
                'used_codes_count' => (int) $totals->used_codes_count,
                'usages_count' => (int) $typeStats->sum('count'),
            ],
            'daily_usages' => $dailyUsages,
            'type_stats' => $typeStats,
        ]);
    }

    public function types()
    {
        return $this->success([
            1 => '金额',
            2 => '时长',
            3 => '流量',
            4 => '重置',
            5 => '套餐',
        ]);
    }

    public function updateTemplate(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_giftcard,id',
            'name' => 'sometimes|required|string|max:255',
            'plan_id' => 'sometimes|nullable|integer|exists:v2_plan,id',
            'period' => 'sometimes|nullable|string|max:64',
            'price' => 'sometimes|nullable|integer|min:0|max:2147483647',
            'enabled' => 'sometimes|boolean',
        ]);

        $giftcard = app(GiftcardWriteService::class)->update((int) $request->input('id'), function (Giftcard $giftcard) use ($request) {
            if ($request->has('name')) {
                $giftcard->name = $request->input('name');
            }
            if ($request->has('enabled')) {
                $giftcard->enabled = (bool)$request->input('enabled');
            }
            if ($request->has('plan_id')) {
                $giftcard->plan_id = $request->input('plan_id');
                $giftcard->type = $giftcard->plan_id ? 5 : 1;
            }
            if ((int)$giftcard->type === 5 && $request->has('period')) {
                $giftcard->value = $this->periodDays($request->input('period'));
            }
            if ((int)$giftcard->type === 1 && $request->has('price')) {
                $giftcard->value = (int)$request->input('price');
            }
        }, true);
        $giftcard->load('plan:id,name');
        return $this->success($this->formatGiftcard($giftcard));
    }

    public function deleteTemplate(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_giftcard,id']);
        $templateId = (int)$request->input('id');
        DB::transaction(function () use ($templateId) {
            // Generation holds this same row lock until every batch is committed.
            Giftcard::whereKey($templateId)->lockForUpdate()->firstOrFail();
            $ids = Giftcard::where('id', $templateId)
                ->orWhere('template_id', $templateId)
                ->orderBy('id')->lockForUpdate()
                ->pluck('id');
            if (GiftcardUsage::whereIn('giftcard_id', $ids)->delete() === false) {
                throw new \RuntimeException('Gift card usage deletion failed.');
            }
            if (Giftcard::whereIn('id', $ids)->delete() !== $ids->count()) {
                throw new \RuntimeException('Gift card template deletion was incomplete.');
            }
        }, 3);
        return $this->success(true);
    }

    public function updateCode(Request $request)
    {
        $id = (int)$request->input('id');
        $request->validate([
            'id' => 'required|integer|exists:v2_giftcard,id',
            'code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('v2_giftcard', 'code')->ignore($id)],
            'enabled' => 'sometimes|boolean',
            'ended_at' => 'sometimes|nullable|integer',
        ]);

        $giftcard = app(GiftcardWriteService::class)->update($id, static function (Giftcard $giftcard) use ($request) {
            $giftcard->fill($request->only(['code', 'enabled', 'ended_at']));
        });
        $giftcard->load('plan:id,name');
        return $this->success($this->formatGiftcard($giftcard));
    }

    public function deleteCode(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_giftcard,id']);
        $id = (int)$request->input('id');
        DB::transaction(function () use ($id) {
            Giftcard::whereKey($id)->lockForUpdate()->firstOrFail();
            if (GiftcardUsage::where('giftcard_id', $id)->delete() === false) {
                throw new \RuntimeException('Gift card usage deletion failed.');
            }
            if (Giftcard::whereKey($id)->delete() !== 1) {
                throw new \RuntimeException('Gift card deletion was incomplete.');
            }
        }, 3);
        return $this->success(true);
    }
}
