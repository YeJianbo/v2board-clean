<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AdminSettingSecrets
{
    public const FIELDS = [
        'server_token', 'email_password', 'telegram_bot_token', 'google_client_secret',
        'recaptcha_key', 'recaptcha_v3_secret_key', 'turnstile_secret_key', 'backup_password',
    ];

    public function serializeGroups(array $groups): array
    {
        foreach ($groups as &$group) {
            if (!is_array($group)) continue;
            foreach (self::FIELDS as $field) {
                if (!array_key_exists($field, $group)) continue;
                $group[$field . '_configured'] = is_string($group[$field]) && $group[$field] !== '';
                $group[$field] = '';
            }
        }
        unset($group);
        return $groups;
    }

    public function prepareChanges(array $changes): array
    {
        $clear = $changes['clear_secrets'] ?? [];
        if (!is_array($clear) || array_values($clear) !== $clear) {
            throw ValidationException::withMessages(['clear_secrets' => '清除密钥必须为字段列表']);
        }
        foreach ($clear as $field) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                throw ValidationException::withMessages(['clear_secrets' => '不支持清除此密钥字段']);
            }
        }
        unset($changes['clear_secrets']);
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                $value = $changes[$field];
                if ($value !== null && !is_string($value)) {
                    throw ValidationException::withMessages([$field => '密钥必须为字符串']);
                }
                if ($value === null || $value === '') unset($changes[$field]);
            }
            if (in_array($field, $clear, true)) {
                if (array_key_exists($field, $changes)) {
                    throw ValidationException::withMessages([$field => '不能同时替换和清除密钥']);
                }
                // Empty form inputs preserve secrets; only this explicit action clears them.
                $changes[$field] = '';
            }
        }
        return $changes;
    }
}
