<?php

namespace App\Services;

class QueueJobDetails
{
    public function summary(object $job, bool $brief = false): array
    {
        $exception = $this->safeText($job->exception ?? '', $brief ? 512 : 65536);
        if ($brief) $exception = mb_substr(explode("\n", $exception)[0], 0, 512);
        return [
            'id' => $job->id,
            'name' => $job->name ?? '',
            'queue' => $job->queue ?? '',
            'connection' => $job->connection ?? '',
            'status' => $job->status ?? (!empty($job->failed_at) ? 'failed' : 'pending'),
            'failed_at' => $job->failed_at ?? null,
            'reserved_at' => $job->reserved_at ?? null,
            'completed_at' => $job->completed_at ?? null,
            'exception' => $exception,
        ];
    }

    public function detail(object $job): array
    {
        return $this->summary($job) + [
            'payload' => $this->safeValue($this->decode($job->payload ?? null)),
            'context' => $this->safeValue($this->decode($job->context ?? null)),
            'retried_by' => $this->safeValue($this->decode($job->retried_by ?? null)),
        ];
    }

    private function decode($value)
    {
        if (!is_string($value)) return $value;
        // Never instantiate serialized job objects when inspecting a queue record.
        if (strlen($value) > 262144) return '[内容过大，未展示]';
        $decoded = json_decode($value, true, 32);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : '[非 JSON 内容，未展示]';
    }

    private function safeValue($value, int $depth = 0)
    {
        if ($depth > 12) return '[嵌套内容已省略]';
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 200, true) as $key => $item) {
                $normalized = preg_replace('/[^a-z0-9]/', '', strtolower((string) $key));
                if (preg_match('/password|passwd|secret|token|authorization|cookie|credential|privatekey|apikey/', $normalized)) {
                    $result[$key] = '[已隐藏]';
                } elseif ($normalized === 'command') {
                    $result[$key] = '[序列化任务内容未展示]';
                } else {
                    $result[$key] = $this->safeValue($item, $depth + 1);
                }
            }
            if (count($value) > 200) $result['_truncated'] = true;
            return $result;
        }
        return is_string($value) ? $this->safeText($value) : $value;
    }

    private function safeText(string $text, int $limit = 65536): string
    {
        // Bound conversion and regex work before reading a large exception body.
        $truncated = strlen($text) > $limit * 4;
        if ($truncated) $text = substr($text, 0, $limit * 4);
        $text = mb_convert_encoding($text, 'UTF-8');
        $urlEnding = $truncated ? '(?:@|\z)' : '@';
        $text = preg_replace('#(https?://)[^\s/@]+:[^\s/@]+' . $urlEnding . '#i', '$1[已隐藏]@', $text);
        $text = preg_replace('/(Bearer\s+)[a-z0-9._~+\/-]+=*/i', '$1[已隐藏]', $text);
        $text = preg_replace('/((?:authorization|cookie)\s*[=:]\s*)[^\r\n]*/i', '$1[已隐藏]', $text);
        $text = preg_replace('~-----BEGIN ([A-Z0-9 ]{0,64}PRIVATE KEY)-----.*?(?:-----END \1-----|\z)~s', '[已隐藏]', $text);
        $pattern = <<<'REGEX'
/((?:["']?)(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|authorization|cookie)(?:["']?)\s*[=:]\s*)(\[已隐藏\]|"(?:[^"\\]++|\\.)*+(?:"|\\?\z)|'(?:[^'\\]++|\\.)*+(?:'|\\?\z)|[^\s&,;}\]]+)/i
REGEX;
        $text = preg_replace_callback($pattern, static function ($match) {
            $quote = $match[2][0];
            return $match[1] . (($quote === '"' || $quote === "'") ? $quote . '[已隐藏]' . $quote : '[已隐藏]');
        }, $text);
        if ($text === null) return '[内容无法安全展示]';
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
            $truncated = true;
        }
        return $text . ($truncated ? "\n[内容已截断]" : '');
    }
}
