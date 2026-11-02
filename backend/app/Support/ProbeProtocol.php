<?php

namespace App\Support;

final class ProbeProtocol
{
    public const CURRENT = 'ravel-v1';
    public const LEGACY = 'v2node-v1';

    private const CURRENT_CACHE_PREFIX = 'ravel_probe_';
    private const LEGACY_CACHE_PREFIX = 'v2node_probe_';

    public static function headerProfiles(): array
    {
        return [
            self::CURRENT => 'X-Ravel',
            self::LEGACY => 'X-V2Node',
        ];
    }

    public static function cacheKey(string $purpose, $identifier, bool $legacy = false): string
    {
        $prefix = $legacy ? self::LEGACY_CACHE_PREFIX : self::CURRENT_CACHE_PREFIX;
        return $prefix . $purpose . ':' . (string) $identifier;
    }

    public static function cacheKeys(string $purpose, $identifier): array
    {
        return [
            self::cacheKey($purpose, $identifier),
            self::cacheKey($purpose, $identifier, true),
        ];
    }

    public static function get($cache, string $purpose, $identifier, $default = null)
    {
        foreach (self::cacheKeys($purpose, $identifier) as $key) {
            $value = $cache->get($key);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    public static function forget($cache, string $purpose, $identifier): void
    {
        foreach (self::cacheKeys($purpose, $identifier) as $key) {
            $cache->forget($key);
        }
    }

    public static function consumeToken($cache, string $purpose, $identifier, string $candidate): bool
    {
        foreach (self::cacheKeys($purpose, $identifier) as $key) {
            $expected = (string) $cache->get($key, '');
            if ($expected !== '' && hash_equals($expected, $candidate)) {
                self::forget($cache, $purpose, $identifier);
                return true;
            }
        }

        return false;
    }
}
