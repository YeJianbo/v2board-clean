<?php

namespace App\Services;

class DepositBonusService
{
    public static function calculate(int $totalAmount): int
    {
        $tiers = config('v2board.deposit_bounus', []);
        if (!is_array($tiers) || !$tiers || (array_key_exists(0, $tiers) && $tiers[0] === null)) {
            return 0;
        }

        $bonus = 0;
        foreach ($tiers as $tier) {
            if (!is_string($tier)) continue;
            $parts = explode(':', $tier);
            if (count($parts) < 2) continue;
            $threshold = self::cents($parts[0]);
            $reward = self::cents($parts[1]);
            if ($threshold !== null && $reward !== null && $totalAmount >= $threshold) {
                $bonus = max($bonus, $reward);
            }
        }
        return $bonus;
    }

    private static function cents(string $value): ?int
    {
        if (!preg_match('/\A([+-]?)([0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE]([+-]?[0-9]+))?\z/', trim($value), $matches)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $matches[2], 2), 2, '');
        $digits = ltrim($whole . $fraction, '0');
        if ($digits === '') return 0;

        // Shift decimal digits directly; extra decimal places still truncate toward zero.
        $shift = 2 - strlen($fraction) + (float) ($matches[3] ?? 0);
        $negative = $matches[1] === '-';
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if ($shift >= 0) {
            if (strlen($digits) + $shift > strlen($limit)) return null;
            $digits .= str_repeat('0', (int) $shift);
        } else {
            $length = strlen($digits) + $shift;
            if ($length <= 0) return 0;
            $digits = substr($digits, 0, (int) $length);
        }
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }
        if ($negative && $digits === $limit) return PHP_INT_MIN;
        return $negative ? -(int) $digits : (int) $digits;
    }
}
