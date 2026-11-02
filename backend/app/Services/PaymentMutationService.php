<?php

namespace App\Services;

use App\Models\Payment;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class PaymentMutationService
{
    public function save(array $data, ?int $id): void
    {
        unset($data['id']);
        DB::transaction(static function () use ($data, $id): void {
            $payment = $id ? Payment::whereKey($id)->lockForUpdate()->firstOrFail() : new Payment();
            if (!$id) $data['uuid'] = Helper::randomChar(8);
            $payment->fill($data);
            if (!$payment->save() || !$payment->exists || !$payment->getKey()) {
                throw new \RuntimeException('Payment configuration save rejected');
            }
        }, 3);
    }

    public function toggle(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $payment = Payment::whereKey($id)->lockForUpdate()->firstOrFail();
            $payment->enable = !(bool) $payment->enable;
            if (!$payment->save()) throw new \RuntimeException('Payment configuration toggle rejected');
        }, 3);
    }

    public function drop(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $payment = Payment::whereKey($id)->lockForUpdate()->firstOrFail();
            if (!$payment->delete()) throw new \RuntimeException('Payment configuration delete rejected');
        }, 3);
    }
}
