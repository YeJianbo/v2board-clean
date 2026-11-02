<?php

namespace App\Services;

use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class SubscriptionTokenService
{
    public static function issueOneTime(string $token): string
    {
        return Cache::lock(self::lockName($token), 30)->block(3, function () use ($token): string {
            $forward = 'otp_'.$token;
            $existing = Cache::get($forward);
            if (is_string($existing) && $existing !== '' && Cache::get('otpn_'.$existing) === $token) return $existing;

            $ticket = Helper::base64EncodeUrlSafe(random_bytes(24));
            $reverse = 'otpn_'.$ticket;
            try {
                if (!Cache::put($reverse, $token, 86400) || !Cache::put($forward, $ticket, 86400)) {
                    throw new \RuntimeException('Subscription token persistence failed');
                }
            } catch (\Throwable $exception) {
                if (Cache::get($reverse) === $token) self::forget($reverse);
                if (Cache::get($forward) === $ticket) self::forget($forward);
                throw $exception;
            }
            return $ticket;
        });
    }

    public static function resolveOneTime(string $ticket, bool $consume): ?string
    {
        $reverse = 'otpn_'.$ticket;
        $token = Cache::get($reverse);
        if (!is_string($token) || $token === '') return null;
        if (!$consume) return $token;

        // Generation and consumption share one lock, including repair of legacy half-written pairs.
        return Cache::lock(self::lockName($token), 30)->block(3, function () use ($token, $ticket, $reverse): ?string {
            if (Cache::get($reverse) !== $token) return null;
            if (!Cache::forget($reverse)) throw new \RuntimeException('Subscription token consumption failed');
            $forward = 'otp_'.$token;
            if (Cache::get($forward) === $ticket) self::forget($forward);
            return $token;
        });
    }

    private static function forget(string $key): void
    {
        if (!Cache::forget($key) && Cache::has($key)) throw new \RuntimeException('Subscription token cleanup failed');
    }

    private static function lockName(string $token): string
    {
        return 'subscription:one-time:'.hash('sha256', $token);
    }
}
