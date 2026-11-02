<?php

namespace App\Support;

class MachineRuntime
{
    public static function kind(array $status): string
    {
        $kind = $status['runtime_kind'] ?? '';
        return in_array($kind, ['ravel', 'ravel-gost', 'v2node'], true) ? $kind : 'v2node';
    }

    public static function repository(array $status): string
    {
        return [
            'ravel' => 'YeJianbo/ravel',
            'ravel-gost' => 'YeJianbo/ravel-gost',
            'v2node' => 'YeJianbo/v2node',
        ][self::kind($status)];
    }

    public static function requestKind(string $path): ?string
    {
        $action = strtolower(basename($path));
        return [
            'ravelconfig' => 'ravel',
            'v2nodeconfig' => 'v2node',
            'relayconfig' => 'ravel-gost',
        ][$action] ?? null;
    }

    public static function ravelConfiguration(array $legacy, bool $canonical = false): array
    {
        $probe = $legacy['probe'];
        if ($canonical) {
            $legacy['nodes'] = array_map(function (array $node) {
                return [
                    'api_host' => $node['ApiHost'],
                    'node_id' => $node['NodeID'],
                    'api_token' => $node['ApiKey'],
                    'timeout_seconds' => $node['Timeout'] ?? 15,
                ];
            }, $legacy['data']);
            unset($legacy['data']);
        }
        $legacy['runtime'] = [
            'kind' => 'ravel',
            ($canonical ? 'control_protocol' : 'protocol') => 'ravel-v1',
            'relay' => $probe['relay'],
            'auto_update' => array_merge($probe['auto_update'], ['repo' => 'YeJianbo/ravel']),
            'network_quality' => $probe['network_quality'],
            'runtime_task' => $probe['runtime_task'] ?? null,
        ];
        $legacy['restart_node_token'] = $legacy['restart_v2node_token'] ?? '';
        unset($legacy['probe'], $legacy['restart_v2node_token'], $legacy['enable_bbr_token']);
        return $legacy;
    }

    public static function normalizeReportedStatus(array $data): array
    {
        if (array_key_exists('runtime_kind', $data)
            && !in_array($data['runtime_kind'], ['ravel', 'ravel-gost', 'v2node'], true)) {
            throw new \InvalidArgumentException('Unknown runtime kind');
        }
        foreach ([
            'runtime_version' => 'ravel_version',
            'runtime_status' => 'ravel_status',
            'control_protocol' => 'runtime_protocol',
        ] as $canonical => $alias) {
            if (array_key_exists($canonical, $data) && array_key_exists($alias, $data)
                && $data[$canonical] !== $data[$alias]) {
                throw new \InvalidArgumentException('Conflicting runtime status fields: ' . $canonical);
            }
            if (array_key_exists($canonical, $data)) {
                $data[$alias] = $data[$canonical];
            } elseif (array_key_exists($alias, $data)) {
                $data[$canonical] = $data[$alias];
            }
        }
        return $data;
    }

    public static function transitionStatus(array $status, string $kind): array
    {
        if (self::kind($status) === $kind) {
            return $status;
        }
        // Host counters survive migration; runtime-specific observations do not.
        foreach ([
            'runtime_version', 'runtime_status', 'control_protocol', 'runtime_protocol',
            'ravel_version', 'ravel_status', 'v2node_version', 'v2node_status', 'version',
            'managed_nodes', 'node_health', 'runtime_health', 'runtime_error',
            'config_desired_revision', 'config_applied_revision', 'config_apply_status', 'config_apply_error',
            'gost_version', 'gost_status', 'gost_rule_count', 'gost_rule_health',
        ] as $field) {
            unset($status[$field]);
        }
        return $status;
    }
}
