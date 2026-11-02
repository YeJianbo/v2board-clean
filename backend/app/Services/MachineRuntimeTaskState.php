<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

class MachineRuntimeTaskState
{
    public static function withMachineLock(Repository $cache, int $machineId, callable $callback)
    {
        $store = $cache->getStore();
        if (!$store instanceof LockProvider) {
            throw new RuntimeException('Runtime task cache must support atomic locks');
        }
        return $store->lock('machine:runtime:task:' . $machineId, 60)->block(2, $callback);
    }

    public static function separateLegacyQueryError(array $status): array
    {
        $error = (string) ($status['runtime_error'] ?? '');
        if (str_starts_with($error, '读取运行日志失败:') || str_starts_with($error, '读取服务状态失败:')) {
            $status['runtime_query_error'] = $error;
            unset($status['runtime_error']);
            if (($status['runtime_health'] ?? '') === 'error') unset($status['runtime_health']);
        }
        return $status;
    }

    public static function apply(array $status, array $result, int $now): array
    {
        $status = self::separateLegacyQueryError($status);
        $readOnly = in_array($result['action'] ?? '', ['logs', 'status'], true);
        $errorKey = $readOnly ? 'runtime_query_error' : 'runtime_error';
        $status[$readOnly ? 'runtime_query_at' : 'runtime_action_at'] = $now;
        if (!$readOnly) {
            $status['runtime_action_status'] = (string) $result['status'];
        }
        if ($result['status'] === 'failed') {
            $status[$errorKey] = mb_substr((string) $result['message'], 0, 255);
        } else {
            unset($status[$errorKey]);
        }
        return $status;
    }
}
