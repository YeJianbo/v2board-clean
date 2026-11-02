<?php
namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use App\Models\Log as LogModel;
use App\Services\AdminSettingSecrets;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    private const MASK = '[FILTERED]';
    private const TEXT_LIMIT = 16000;
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 100;
    private const FAILURE_COOLDOWN_SECONDS = 60;
    private const DATABASE_ERROR = '数据库异常详情已隐藏，请查看服务器日志';
    private const DATABASE_EXCEPTIONS = [\PDOException::class, \Illuminate\Database\QueryException::class];

    private static int $disabledUntil = 0;

    private const SENSITIVE_KEYS = [
        'auth_data',
        'authorization',
        'server_token',
        'credentials',
        'capability_key',
        'capability-key',
        'access_token',
        'refresh_token',
        'user_token',
        'token',
        'password',
        'passwd',
        'pwd',
        'secret',
        'api_key',
        'apikey',
        'api-key',
        'key',
        'private_key',
        'public_key',
        'obfs_password',
        'communication_key',
        'communication_secret',
        'machine_key',
        'machine_secret',
        'cfkey',
        'cf_key',
    ];

    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    public function sanitizeStoredLog(array $log): array
    {
        if (isset($log['title']) && is_string($log['title'])) {
            $log['title'] = $this->sanitizeString($log['title']);
        }
        if (isset($log['uri']) && is_string($log['uri'])) {
            $log['uri'] = $this->sanitizeUri($log['uri']);
        }
        foreach (['data', 'context'] as $field) {
            if (!isset($log[$field]) || !is_string($log[$field])) continue;
            // Never turn an oversized structured value into an unredacted text prefix.
            if (strlen($log[$field]) > self::TEXT_LIMIT) {
                $log[$field] = $this->safeJson(['__redacted' => 'size_limit']);
                continue;
            }
            $decoded = json_decode($log[$field], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if ($field === 'context' && ($this->isDatabaseExceptionSummary($decoded)
                    || $this->isDatabaseExceptionSummary(is_array($decoded) ? ($decoded['exception'] ?? null) : null))) {
                    if (isset($log['title'])) $log['title'] = self::DATABASE_ERROR;
                }
                $log[$field] = $this->safeJson($this->sanitizeData($decoded));
            } elseif (preg_match('/^\s*[\[{\"]/', $log[$field]) === 1) {
                $log[$field] = $this->safeJson(['__redacted' => 'invalid_json']);
            } else {
                $log[$field] = $this->sanitizeString($log[$field]);
            }
        }
        return $log;
    }

    private function sanitizeRequestData(\Illuminate\Http\Request $request): array
    {
        $data = $request->all();
        if (preg_match('~/agent/(start|chat)$~', $request->path()) && array_key_exists('message', $data)) {
            $data['message'] = self::MASK;
        }
        return $this->sanitizeData($data);
    }

    protected function write(array $record): void
    {
        if (self::$disabledUntil > time()) {
            return;
        }

        try{
            if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
                if ($record['context']['exception'] instanceof \PDOException) {
                    $record['message'] = self::DATABASE_ERROR;
                }
                $record['context']['exception'] = $this->sanitizeThrowable($record['context']['exception']);
            }
            $request = null;
            if (!app()->runningInConsole() && app()->bound('request')) {
                try {
                    $request = app('request');
                } catch (\Throwable $e) {
                    $request = null;
                }
            }
            $record['request_data'] = $request ? $this->sanitizeRequestData($request) : [];
            if (isset($record['context'])) {
                $record['context'] = $this->sanitizeData($record['context']);
            }
            $datetime = $record['datetime'] ?? null;
            $timestamp = $datetime instanceof \DateTimeInterface
                ? $datetime->getTimestamp()
                : strtotime((string)$datetime);
            if (!$timestamp) {
                $timestamp = time();
            }
            $log = [
                'title' => $this->sanitizeString((string)($record['message'] ?? '')),
                'level' => $this->limitString((string)($record['level_name'] ?? ''), 11),
                'host' => $this->limitString((string)($record['request_host'] ?? ($request ? $request->getSchemeAndHttpHost() : '')), 255),
                'uri' => $this->limitString($this->sanitizeUri($record['request_uri'] ?? ($request ? $request->getRequestUri() : 'console')), 255),
                'method' => $this->limitString((string)($record['request_method'] ?? ($request ? $request->getMethod() : 'CLI')), 11),
                'ip' => $this->limitString((string)($request ? $request->getClientIp() : ''), 128),
                'data' => $this->safeJson($record['request_data']),
                'context' => isset($record['context']) ? $this->safeJson($record['context']) : '',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            LogModel::insert(
                $log
            );
        } catch (\Throwable $e) {
            self::$disabledUntil = time() + self::FAILURE_COOLDOWN_SECONDS;
            error_log(sprintf(
                'MysqlLoggerHandler disabled for %d seconds after failure: %s in %s:%d',
                self::FAILURE_COOLDOWN_SECONDS,
                $this->limitBytes($this->sanitizeString($e->getMessage()), 2000),
                $e->getFile(),
                $e->getLine()
            ));
        }
    }

    private function sanitizeData($value, string $key = '', int $depth = 0)
    {
        $remainingItems = self::MAX_ITEMS;
        $remainingBytes = self::TEXT_LIMIT;
        return $this->sanitizeValue($value, $key, $depth, $remainingItems, $remainingBytes);
    }

    private function sanitizeValue($value, string $key, int $depth, int &$remainingItems, int &$remainingBytes)
    {
        if ($this->isSensitiveKey($key)) {
            $remainingBytes = max(0, $remainingBytes - strlen(self::MASK));
            return self::MASK;
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[DEPTH_LIMIT]';
        }

        if ($value instanceof \Throwable) {
            return $this->sanitizeValue($this->sanitizeThrowable($value), '', $depth + 1, $remainingItems, $remainingBytes);
        }

        if (is_array($value)) {
            if ($this->isDatabaseExceptionSummary($value)) $value = $this->databaseExceptionSummary($value);
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                if ($remainingItems <= 0 || $remainingBytes <= 0) {
                    $sanitized['__truncated'] = true;
                    break;
                }
                --$remainingItems;
                $safeKey = is_string($childKey) ? $this->limitBytes($childKey, min(256, $remainingBytes)) : $childKey;
                if (is_string($safeKey)) $remainingBytes -= strlen($safeKey);
                $sanitized[$safeKey] = $this->sanitizeValue($childValue, (string)$childKey, $depth + 1, $remainingItems, $remainingBytes);
            }
            if ($remainingItems <= 0 || $remainingBytes <= 0) $sanitized['__truncated'] = true;
            return $sanitized;
        }

        if (is_object($value)) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            $class = $this->limitBytes(get_class($value), min(256, $remainingBytes));
            $remainingBytes = max(0, $remainingBytes - strlen($class));
            return [
                '__class' => $class,
                '__summary' => '[OBJECT]',
            ];
        }

        if (is_string($value)) {
            $sanitized = $this->sanitizeString($this->limitBytes($value, $remainingBytes));
            $remainingBytes = max(0, $remainingBytes - strlen($sanitized));
            return $sanitized;
        }

        return $value;
    }

    private function sanitizeThrowable(\Throwable $throwable): array
    {
        if ($throwable instanceof \PDOException) {
            return $this->databaseExceptionSummary([
                'class' => get_class($throwable), 'file' => $throwable->getFile(),
                'line' => $throwable->getLine(), 'code' => $throwable->getCode(),
            ]);
        }
        return [
            'class' => get_class($throwable),
            'message' => $this->limitString($this->sanitizeString($throwable->getMessage()), 2000),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'code' => $throwable->getCode(),
            'trace' => array_map(function ($frame) {
                return [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'function' => $frame['function'] ?? null,
                    'class' => $frame['class'] ?? null,
                ];
            }, array_slice($throwable->getTrace(), 0, 20)),
        ];
    }

    private function isDatabaseExceptionSummary($value): bool
    {
        return is_array($value) && in_array($value['class'] ?? null, self::DATABASE_EXCEPTIONS, true);
    }

    private function databaseExceptionSummary(array $value): array
    {
        return ['class' => $value['class'], 'message' => self::DATABASE_ERROR]
            + array_intersect_key($value, ['file' => true, 'line' => true, 'code' => true]);
    }

    private function safeJson($value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if (!is_string($json)) {
            $json = '';
        }

        if (strlen($json) <= self::TEXT_LIMIT) return $json;
        // A JSON prefix may double in size when quoted; reserve room for the wrapper.
        $preview = $this->limitBytes($json, intdiv(self::TEXT_LIMIT - 64, 2));
        return json_encode(['__truncated' => true, 'preview' => $preview],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            ?: '{"__truncated":true}';
    }

    private function sanitizeString(string $value): string
    {
        $value = $this->limitBytes($value, self::TEXT_LIMIT);
        // QueryException expands bindings into its message; field-name masking cannot recover them.
        if (preg_match('/(?:\bSQLSTATE\[[A-Z0-9]{5}\]|\(SQL:)/i', $value) === 1) return self::DATABASE_ERROR;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer ' . self::MASK, $value) ?? $value;
        // Also mask tokens cut at the byte boundary, not only complete JWTs.
        $value = preg_replace('/eyJ[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,2}/', self::MASK, $value) ?? $value;

        return $this->limitBytes($value, self::TEXT_LIMIT);
    }

    private function limitBytes(string $value, int $limit): string
    {
        if ($limit <= 0) return '';
        if (strlen($value) <= $limit) return $value;
        return function_exists('mb_strcut') ? mb_strcut($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private function limitString(string $value, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $limit
                ? mb_substr($value, 0, $limit, 'UTF-8')
                : $value;
        }

        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    private function sanitizeUri(string $uri): string
    {
        $uri = $this->limitBytes($uri, self::TEXT_LIMIT);
        if ($uri === '' || strpos($uri, '?') === false) {
            return $this->sanitizeString($uri);
        }

        [$path, $query] = explode('?', $uri, 2);
        $fragment = '';
        if (strpos($query, '#') !== false) {
            [$query, $fragment] = explode('#', $query, 2);
            $fragment = '#' . $fragment;
        }

        parse_str($query, $params);
        $params = $this->sanitizeData($params);
        $safeQuery = http_build_query($params);

        return $this->sanitizeString($path . ($safeQuery ? '?' . $safeQuery : '') . $fragment);
    }

    private function isSensitiveKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        if (in_array($normalized, self::SENSITIVE_KEYS, true)
            || in_array($normalized, AdminSettingSecrets::FIELDS, true)) {
            return true;
        }

        return preg_match('/(^|_)(password|passwd|pwd|token|secret|authorization|auth_data|credentials|capability_?key|api_?key|private_?key|obfs_?password|cf_?key)(_|$)/', $normalized) === 1;
    }
}
