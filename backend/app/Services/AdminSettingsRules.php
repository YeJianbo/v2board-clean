<?php

namespace App\Services;

use Illuminate\Support\Arr;

class AdminSettingsRules
{
    public static function errors(array $settings, array $changes): array
    {
        $errors = [];
        foreach ([['minute', 'hour'], ['hour', 'daily'], ['domain_hour', 'domain_daily']] as [$short, $long]) {
            $a = 'traffic_retention_' . $short; $b = 'traffic_retention_' . $long;
            if (!array_key_exists($a, $changes) && !array_key_exists($b, $changes)) continue;
            $shortDays = max(1, min(3650, (int) Arr::get($settings, $a, TrafficRetention::DEFAULTS[$short])));
            $longDays = max(1, min(3650, (int) Arr::get($settings, $b, TrafficRetention::DEFAULTS[$long])));
            if ($shortDays > $longDays) {
                $errors[array_key_exists($b, $changes) ? $b : $a] = '较粗粒度的明细保留天数不能短于细粒度明细';
            }
        }
        if (array_intersect(array_keys($changes), ['frontend_user_path', 'secure_path', 'subscribe_path']) === []) return $errors;
        $user = Arr::get($settings, 'frontend_user_path', 'user');
        $admin = Arr::get($settings, 'secure_path', Arr::get($settings, 'frontend_admin_path', hash('crc32b', (string) config('app.key'))));
        $subscribe = Arr::get($settings, 'subscribe_path', '');
        $invalidType = is_array($user) || is_object($user) || is_array($admin) || is_object($admin)
            || is_array($subscribe) || is_object($subscribe);
        if (!$invalidType) {
            $user = (string) $user; $admin = (string) $admin;
            $subscribe = explode('/', trim((string) $subscribe, '/'))[0];
        }
        if ($invalidType || !preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $user)
            || in_array(strtolower($user), ['admin', 'api', 'assets', 'theme', 'storage', 'vendor', 'livewire', '_debugbar'], true)
            || $user === $admin || ($subscribe !== '' && $user === $subscribe)) {
            $errors['frontend_user_path'] = '用户入口须为3-64位字母、数字、下划线或连字符，且不能与后台或系统路由冲突';
        }
        return $errors;
    }
}
