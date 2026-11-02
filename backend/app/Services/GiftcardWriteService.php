<?php

namespace App\Services;

use App\Models\Giftcard;
use Illuminate\Support\Facades\DB;

class GiftcardWriteService
{
    public function create(array $attributes): Giftcard
    {
        return DB::transaction(static function () use ($attributes) {
            $giftcard = Giftcard::create($attributes);
            if (!$giftcard->exists || !$giftcard->getKey()) {
                throw new \RuntimeException('Gift card creation was rejected.');
            }
            return $giftcard;
        }, 3);
    }

    public function update(int $id, callable $change, bool $templateOnly = false): Giftcard
    {
        return DB::transaction(static function () use ($id, $change, $templateOnly) {
            $query = Giftcard::query()->whereKey($id);
            if ($templateOnly) $query->whereNull('template_id');
            $giftcard = $query->lockForUpdate()->firstOrFail();
            $change($giftcard);
            if (!$giftcard->save() || !$giftcard->exists || !$giftcard->getKey()) {
                throw new \RuntimeException('Gift card save was rejected.');
            }
            return $giftcard;
        }, 3);
    }
}
