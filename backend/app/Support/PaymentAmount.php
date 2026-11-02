<?php

namespace App\Support;

class PaymentAmount
{
    public static function fromDecimal($amount): ?int
    {
        if ((!is_string($amount) && !is_int($amount)) || strlen((string) $amount) > 32 ||
            !preg_match('/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/D', (string) $amount, $parts)) return null;
        $minor = ltrim($parts[1] . str_pad($parts[2] ?? '', 2, '0'), '0') ?: '0';
        $max = (string) PHP_INT_MAX;
        if (strlen($minor) > strlen($max) || (strlen($minor) === strlen($max) && strcmp($minor, $max) > 0)) return null;
        return (int) $minor;
    }
}
