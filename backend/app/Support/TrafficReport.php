<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class TrafficReport
{
    public static function nodeRateRules(): array
    {
        return array_merge(self::rateRules(), [static function ($attribute, $value, $fail): void {
            // Node tables retain the submitted rate in VARCHAR(11).
            if (strlen((string) $value) > 11) {
                $fail('节点倍率的保存值不能超过11个字符');
            }
        }]);
    }

    public static function rateRules(): array
    {
        return ['bail', 'required', static function ($attribute, $value, $fail): void {
            try {
                self::normalize([], $value);
            } catch (ValidationException $exception) {
                $fail('节点倍率须为非负有限数值，保留两位小数后不能超过99999999.99');
            }
        }];
    }

    public static function read(Request $request): array
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if (str_starts_with($contentType, 'application/x-www-form-urlencoded') ||
            str_starts_with($contentType, 'multipart/form-data')) {
            return $request->request->all();
        }
        $body = $request->getContent();
        if ($body === '' && !$request->isJson()) {
            return $request->request->all();
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            self::invalid();
        }
        if (!is_array($data)) {
            self::invalid();
        }
        return $data;
    }

    public static function fromRows(array $rows): array
    {
        $data = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['user_id'], $row['u'], $row['d'])) {
                self::invalid();
            }
            $id = self::unsignedInteger($row['user_id']);
            if ($id === null || $id === 0 || array_key_exists($id, $data)) {
                self::invalid();
            }
            $data[$id] = [$row['u'], $row['d']];
        }
        return self::normalize($data);
    }

    public static function normalize(array $data, $rate = 1): array
    {
        // Statistics store rates as DECIMAL(10,2).
        if (!is_numeric($rate) || !is_finite((float) $rate) || $rate < 0 || round((float) $rate, 2) > 99999999.99) {
            self::invalidAccounting();
        }
        $result = [];
        $totalUpload = 0;
        $totalDownload = 0;
        foreach ($data as $userId => $traffic) {
            $id = self::unsignedInteger($userId);
            if ($id === null || $id === 0 || $id > 2147483647 || !is_array($traffic) || count($traffic) !== 2 ||
                !array_key_exists(0, $traffic) || !array_key_exists(1, $traffic)) {
                self::invalid();
            }
            $upload = self::unsignedInteger($traffic[0]);
            $download = self::unsignedInteger($traffic[1]);
            if ($upload === null || $download === null || array_key_exists($id, $result)) {
                self::invalid();
            }
            if ($upload > PHP_INT_MAX - $totalUpload || $download > PHP_INT_MAX - $totalDownload) {
                self::invalidAccounting();
            }
            $totalUpload += $upload;
            $totalDownload += $download;
            foreach ([$upload, $download] as $bytes) {
                $weighted = $bytes * $rate;
                // Float PHP_INT_MAX rounds up; equality is already unsafe to cast to int.
                if (is_float($weighted) && (!is_finite($weighted) || $weighted >= (float) PHP_INT_MAX)) {
                    self::invalidAccounting();
                }
            }
            $result[$id] = [$upload, $download];
        }
        return $result;
    }

    private static function unsignedInteger($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/\A[0-9]+\z/D', $value)) {
            return null;
        }
        $digits = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum) ||
            (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }
        return (int) $digits;
    }

    private static function invalid(): void
    {
        throw ValidationException::withMessages([
            'traffic' => '流量上报必须包含有效用户 ID 和非负整数的上传、下载字节数。',
        ]);
    }

    private static function invalidAccounting(): void
    {
        throw ValidationException::withMessages([
            'traffic' => '节点倍率无效或本次流量超出可计费范围，请检查上报数据。',
        ]);
    }
}
