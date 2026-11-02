<?php

namespace App\Contracts;

interface PaymentInterface
{
    public function form(): array;

    public function pay($order): array;

    /**
     * Return verified trade_no/callback_no, or "success" for a signed non-settlement event.
     * Optional paid_amount is an integer in the panel's minor currency unit, not the
     * gateway's native currency. Include it only when authenticated settlement data
     * proves that amount; never copy it from unverified request fields.
     */
    public function notify($params);
}
