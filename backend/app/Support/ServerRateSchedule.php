<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ServerRateSchedule
{
    public static function fromRequest(Request $request): array
    {
        if (!$request->exists('rate_time_enable') && !$request->exists('rate_time_ranges')) return [];
        $request->validate(['rate_time_enable' => 'required|boolean']);
        if (!(bool) $request->input('rate_time_enable')) {
            // Disabling keeps the last saved rules; hidden unsaved drafts are not submitted.
            return ['rate_time_enable' => false];
        }
        $request->validate(['rate_time_ranges' => 'required|array|min:1|max:32']);
        return [
            'rate_time_enable' => true,
            'rate_time_ranges' => self::normalizeRules($request->input('rate_time_ranges'), $request->input('rate')),
        ];
    }

    public static function normalizeRules(array $rules, $baseRate): array
    {
        TrafficReport::normalize([], $baseRate);
        if (!array_is_list($rules) || count($rules) < 1 || count($rules) > 32) {
            self::invalid('rate_time_ranges', '动态倍率须设置1至32条时间段规则');
        }
        $result = $intervals = [];
        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) self::invalid("rate_time_ranges.$index", '时间段规则格式错误');
            $start = self::minute($rule['start'] ?? null, false, "rate_time_ranges.$index.start");
            $end = self::minute($rule['end'] ?? null, true, "rate_time_ranges.$index.end");
            if ($start === $end) self::invalid("rate_time_ranges.$index.end", '起止时间不能相同，全天请设00:00至24:00');
            $rate = $rule['rate'] ?? null;
            try {
                TrafficReport::normalize([], $rate);
                TrafficReport::normalize([], $baseRate * $rate);
            } catch (ValidationException $error) {
                self::invalid("rate_time_ranges.$index.rate", '倍率乘数或与基础倍率相乘的结果超出计费范围');
            }
            $result[] = ['start' => trim($rule['start']), 'end' => trim($rule['end']), 'rate' => (float) $rate];
            if ($start < $end) $intervals[] = [$start, $end];
            else {
                $intervals[] = [$start, 1440];
                if ($end > 0) $intervals[] = [0, $end];
            }
        }
        usort($intervals, static fn ($a, $b) => $a[0] <=> $b[0]);
        $previousEnd = -1;
        foreach ($intervals as [$start, $end]) {
            if ($start < $previousEnd) self::invalid('rate_time_ranges', '动态倍率时间段不能重叠');
            $previousEnd = $end;
        }
        return $result;
    }

    public static function resolve(array $server, int $receivedAt)
    {
        $baseRate = $server['rate'] ?? 1;
        if (empty($server['rate_time_enable'])) return $baseRate;
        $rules = self::normalizeRules($server['rate_time_ranges'] ?? [], $baseRate);
        $time = (new \DateTimeImmutable('@' . $receivedAt))->setTimezone(new \DateTimeZone(config('app.timezone', 'UTC')));
        $minute = (int) $time->format('H') * 60 + (int) $time->format('i');
        foreach ($rules as $rule) {
            $start = (int) substr($rule['start'], 0, 2) * 60 + (int) substr($rule['start'], 3, 2);
            $end = (int) substr($rule['end'], 0, 2) * 60 + (int) substr($rule['end'], 3, 2);
            if ($start < $end ? $minute >= $start && $minute < $end : $minute >= $start || $minute < $end) {
                return $baseRate * $rule['rate'];
            }
        }
        return $baseRate;
    }

    private static function minute($value, bool $allowEndOfDay, string $field): int
    {
        if (is_string($value)) $value = trim($value);
        if ($allowEndOfDay && $value === '24:00') return 1440;
        if (!is_string($value) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', trim($value))) {
            self::invalid($field, $allowEndOfDay ? '请输入HH:mm时间，结束时间可为24:00' : '请输入00:00至23:59之间的HH:mm时间');
        }
        $value = trim($value);
        return (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);
    }

    private static function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
