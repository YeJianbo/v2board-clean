<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerV2node;
use App\Models\User;
use App\Models\Machine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class NodeSyncService
{
    private const RESTART_TOKEN_TTL_SECONDS = 300;

    public static function scheduleMachineNodesChanged(array $machineIds): void
    {
        $machineIds = array_values(array_unique(array_filter(array_map('intval', $machineIds), static fn ($id) => $id > 0)));
        DB::afterCommit(static function () use ($machineIds) {
            foreach ($machineIds as $machineId) {
                try {
                    self::notifyMachineNodesChanged($machineId);
                } catch (\Throwable $exception) {
                    self::logMachineNotificationFailure($machineId, 'node_registry', $exception);
                }
                try {
                    Machine::probeCache()->put(
                        'v2node_probe_restart:' . $machineId,
                        (string) time() . '-' . Str::random(12),
                        self::RESTART_TOKEN_TTL_SECONDS
                    );
                } catch (\Throwable $exception) {
                    self::logMachineNotificationFailure($machineId, 'restart_hint', $exception);
                }
            }
            try {
                Machine::forgetAdminFetchCache();
            } catch (\Throwable $exception) {
                self::logMachineNotificationFailure(null, 'relay_cache', $exception);
            }
        });
    }

    private static function logMachineNotificationFailure(?int $machineId, string $stage, \Throwable $exception): void
    {
        try {
            Log::warning('Node machine notification failed', [
                'machine_id' => $machineId, 'stage' => $stage,
                'exception_type' => get_class($exception), 'exception' => $exception,
            ]);
        } catch (\Throwable $loggingException) {
            // A diagnostic failure cannot undo an already committed node change.
        }
    }

    /**
     * Check if node has active WS connection
     */
    public static function isNodeOnline(int $nodeId): bool
    {
        return (bool) Cache::get("node_ws_alive:{$nodeId}");
    }

    /**
     * Push node config update
     */
    public static function notifyConfigUpdated(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);
    }

    /**
     * Push all users to all nodes in the group
     */
    public static function notifyUsersUpdatedByGroup(int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            $users = ServerService::getAvailableUsers($server)->toArray();
            self::push($server->id, 'sync.users', ['users' => $users]);
        }
    }

    /**
     * Push user changes (add/remove) to affected nodes
     */
    public static function notifyUserChanged(User $user): void
    {
        if (!$user->group_id)
            return;

        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)->get();
        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            if ($user->isAvailable()) {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'add',
                    'users' => [
                        [
                            'id' => $user->id,
                            'uuid' => $user->uuid,
                            'speed_limit' => $user->speed_limit,
                            'device_limit' => $user->device_limit,
                        ]
                    ],
                ]);
            } else {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => [['id' => $user->id]],
                ]);
            }
        }
    }

    /**
     * Push user removal from a specific group's nodes
     */
    public static function notifyUserRemovedFromGroup(int $userId, int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            if (!self::isNodeOnline($server->id))
                continue;

            self::push($server->id, 'sync.user.delta', [
                'action' => 'remove',
                'users' => [['id' => $userId]],
            ]);
        }
    }

    /**
     * Full sync: push config + users to a node
     */
    public static function notifyFullSync(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);

        $users = ServerService::getAvailableUsers($node)->toArray();
        self::push($nodeId, 'sync.users', ['users' => $users]);
    }

    /**
     * Notify machine that its node set has changed.
     * Always publishes via Redis so the WS process can update its in-memory registry.
     */
    public static function notifyMachineNodesChanged(int $machineId): void
    {
        $nodeList = ServerV2node::query()
            ->where('machine_id', $machineId)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn($n) => [
                'id' => (int) $n->id,
                'type' => 'v2node',
                'name' => (string) $n->name,
            ])
            ->values()
            ->toArray();

        self::pushMachine($machineId, 'sync.nodes', ['nodes' => $nodeList]);
    }

    /**
     * Publish a push command to Redis — picked up by the Workerman WS server
     */
    public static function push(int $nodeId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'node_id' => $nodeId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis publish failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
                'event' => $event,
            ]);
        }
    }

    /**
     * Publish a machine-level push command to Redis — picked up by the Workerman WS server
     */
    public static function pushMachine(int $machineId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'machine_id' => $machineId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis machine publish failed: {$e->getMessage()}", [
                'machine_id' => $machineId,
                'event' => $event,
            ]);
        }
    }
}
