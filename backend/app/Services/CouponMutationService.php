<?php

namespace App\Services;

use App\Models\Coupon;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class CouponMutationService
{
    public function save(array $data, ?int $id): void
    {
        unset($data['id'], $data['generate_count']);
        DB::transaction(static function () use ($data, $id): void {
            $coupon = $id ? Coupon::whereKey($id)->lockForUpdate()->firstOrFail() : new Coupon();
            if (!$id && !isset($data['code'])) $data['code'] = Helper::randomChar(8);
            $coupon->fill($data);
            if (!$coupon->save() || !$coupon->exists || !$coupon->getKey()) {
                throw new \RuntimeException('Coupon save rejected');
            }
        }, 3);
    }

    public function toggle(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $coupon = Coupon::whereKey($id)->lockForUpdate()->firstOrFail();
            $coupon->show = $coupon->show ? 0 : 1;
            if (!$coupon->save()) throw new \RuntimeException('Coupon visibility save rejected');
        }, 3);
    }

    public function drop(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $coupon = Coupon::whereKey($id)->lockForUpdate()->firstOrFail();
            if (!$coupon->delete()) throw new \RuntimeException('Coupon delete rejected');
        }, 3);
    }
}
