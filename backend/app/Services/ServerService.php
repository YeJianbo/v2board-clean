<?php

namespace App\Services;

use App\Models\ServerHysteria;
use App\Models\ServerLog;
use App\Models\ServerRoute;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerV2node;
use App\Models\User;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerAnytls;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerService
{
    private $availableServerCollections;

    private function availableServerCollection(string $type)
    {
        if ($this->availableServerCollections === null) {
            $loaders = [
                'vless' => static function () {
                    return ServerVless::orderBy('sort', 'ASC')->get();
                },
                'vmess' => static function () {
                    return ServerVmess::orderBy('sort', 'ASC')->get();
                },
                'trojan' => static function () {
                    return ServerTrojan::orderBy('sort', 'ASC')->get();
                },
                'tuic' => static function () {
                    return ServerTuic::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'hysteria' => static function () {
                    return ServerHysteria::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'shadowsocks' => static function () {
                    return ServerShadowsocks::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'anytls' => static function () {
                    return ServerAnytls::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
                'v2node' => static function () {
                    return ServerV2node::orderBy('sort', 'ASC')->get()->keyBy('id');
                },
            ];
            $cacheKeys = [];
            foreach (array_keys($loaders) as $serverType) {
                $cacheKeys[$serverType] = 'servers_' . $serverType;
            }
            $cachedCollections = Cache::many(array_values($cacheKeys));
            $this->availableServerCollections = [];

            foreach ($loaders as $serverType => $loader) {
                $cacheKey = $cacheKeys[$serverType];
                $collection = $cachedCollections[$cacheKey] ?? null;
                if ($collection === null) {
                    $collection = $loader();
                    Cache::put($cacheKey, $collection, 60);
                }
                $this->availableServerCollections[$serverType] = $collection;
            }
        }

        return $this->availableServerCollections[$type];
    }

    private function serverStatuses(array $servers, bool $includeOnlineUsers = true): array
    {
        $definitions = [];
        $cacheKeys = [];
        foreach ($servers as $index => $server) {
            $serverType = strtoupper((string) ($server['type'] ?? ''));
            $serverIds = array_values(array_unique(array_filter(
                $this->serverStatusIds($server),
                static function ($serverId) {
                    return $serverId !== null && $serverId !== '' && (int) $serverId > 0;
                }
            )));
            $definitions[$index] = [
                'type' => $serverType,
                'ids' => $serverIds,
            ];

            if ($serverType === '') {
                continue;
            }
            foreach ($serverIds as $serverId) {
                if ($includeOnlineUsers) $cacheKeys[] = CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $serverId);
                $cacheKeys[] = CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $serverId);
                $cacheKeys[] = CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $serverId);
            }
        }
        $statusValues = $cacheKeys ? Cache::many(array_values(array_unique($cacheKeys))) : [];

        $statuses = [];
        foreach ($definitions as $index => $definition) {
            $online = 0;
            $lastCheckAt = 0;
            $lastPushAt = 0;
            foreach ($definition['ids'] as $serverId) {
                $serverType = $definition['type'];
                if ($includeOnlineUsers) {
                    $online = max(
                        $online,
                        (int) ($statusValues[CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $serverId)] ?? 0)
                    );
                }
                $lastCheckAt = max(
                    $lastCheckAt,
                    (int) ($statusValues[CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $serverId)] ?? 0)
                );
                $lastPushAt = max(
                    $lastPushAt,
                    (int) ($statusValues[CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $serverId)] ?? 0)
                );
            }

            $statuses[$index] = [
                'online' => $online,
                'last_check_at' => $lastCheckAt,
                'last_push_at' => $lastPushAt,
            ];
        }

        return $statuses;
    }

    private function serverStatusIds(array $server): array
    {
        return [
            $server['id'] ?? null,
            $server['parent_id'] ?? null
        ];
    }

    private function panelSetting(string $key, $default = null)
    {
        $configValue = config('v2board.' . $key);
        if ($configValue !== null && $configValue !== '') {
            return $configValue;
        }

        $dbValue = DB::table('v2_settings')->where('name', $key)->value('value');
        if ($dbValue !== null && $dbValue !== '') {
            return $dbValue;
        }

        return $default;
    }

    private function matchesUserGroup(User $user, $groupIds): bool
    {
        return is_array($groupIds) && in_array($user->group_id, $groupIds);
    }

    public function getAvailableVless(User $user): array
    {
        $servers = [];
        $server = clone $this->availableServerCollection('vless');
        foreach ($server as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            // Response transformations must leave the cached source models unchanged.
            $server[$key] = clone $v;
            $server[$key]['type'] = 'vless';
            if (strpos($server[$key]['port'], '-') !== false) {
                $server[$key]['port'] = Helper::randomPort($server[$key]['port']);
            }
            if (isset($server[$key]['tls_settings'])) {
                $server[$key]['tls_settings'] = array_diff_key(
                    $server[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($server, $key) {
                        return isset($server[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($server[$key]['encryption_settings'])) {
                if (isset($server[$key]['encryption_settings']['private_key'])) {
                    $server[$key]['encryption_settings'] = array_diff_key($server[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $servers[] = $server[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableVmess(User $user): array
    {
        $servers = [];
        $vmess = clone $this->availableServerCollection('vmess');
        foreach ($vmess as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            $vmess[$key] = clone $v;
            $vmess[$key]['type'] = 'vmess';
            if (strpos($vmess[$key]['port'], '-') !== false) {
                $vmess[$key]['port'] = Helper::randomPort($vmess[$key]['port']);
            }
            $servers[] = $vmess[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableTrojan(User $user): array
    {
        $servers = [];
        $trojan = clone $this->availableServerCollection('trojan');
        foreach ($trojan as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            $trojan[$key] = clone $v;
            $trojan[$key]['type'] = 'trojan';
            if (strpos($trojan[$key]['port'], '-') !== false) {
                $trojan[$key]['port'] = Helper::randomPort($trojan[$key]['port']);
            }
            $servers[] = $trojan[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableTuic(User $user)
    {
        $availableServers = [];
        $servers = clone $this->availableServerCollection('tuic');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            $servers[$key] = clone $v;
            $servers[$key]['type'] = 'tuic';
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }
            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableHysteria(User $user)
    {
        $availableServers = [];
        $servers = clone $this->availableServerCollection('hysteria');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            $servers[$key] = clone $v;
            $servers[$key]['type'] = 'hysteria';
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }
            $servers[$key]['server_key'] = Helper::getServerKey($servers[$key]['created_at'], 16);
            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableShadowsocks(User $user)
    {
        $servers = [];
        $shadowsocks = clone $this->availableServerCollection('shadowsocks');
        foreach ($shadowsocks as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            $shadowsocks[$key] = clone $v;
            $shadowsocks[$key]['type'] = 'shadowsocks';
            if (strpos($v['port'], '-') !== false) {
                $shadowsocks[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($shadowsocks[$v['parent_id']])) {
                $shadowsocks[$key]['created_at'] = $shadowsocks[$v['parent_id']]['created_at'];
            }
            if ($v['obfs'] === 'http') {
                $shadowsocks[$key]['obfs'] = 'http';
                $shadowsocks[$key]['obfs-host'] = $v['obfs_settings']['host'];
                $shadowsocks[$key]['obfs-path'] = $v['obfs_settings']['path'];
            }
            $servers[] = $shadowsocks[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableAnyTLS(User $user)
    {
        $servers = [];
        $anytls = clone $this->availableServerCollection('anytls');
        foreach ($anytls as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            $anytls[$key] = clone $v;
            $anytls[$key]['type'] = 'anytls';
            if (strpos($v['port'], '-') !== false) {
                $anytls[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($anytls[$v['parent_id']])) {
                $anytls[$key]['created_at'] = $anytls[$v['parent_id']]['created_at'];
            }
            $servers[] = $anytls[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableV2node(User $user)
    {
        $servers = [];
        $v2node = clone $this->availableServerCollection('v2node');
        foreach ($v2node as $key => $v) {
            if (!$v['show']) continue;
            if (!$this->matchesUserGroup($user, $v['group_id'])) continue;
            $v2node[$key] = clone $v;
            $v2node[$key]['type'] = 'v2node';
            if (isset($v2node[$v['parent_id']])) {
                $v2node[$key]['created_at'] = $v2node[$v['parent_id']]['created_at'];
                $parent = $v2node[$v['parent_id']];
                if ((int) $parent['tls'] === 1) {
                    // A relay serves its landing node's certificate, not a separate identity.
                    $identityKeys = array_flip(['cert_mode', 'certificate_fingerprint', 'certificate_public_key_sha256', 'allow_insecure']);
                    $v2node[$key]['tls_settings'] = array_merge(
                        array_diff_key($v2node[$key]['tls_settings'] ?? [], $identityKeys),
                        array_intersect_key($parent['tls_settings'] ?? [], $identityKeys)
                    );
                    if (!empty($parent['tls_settings']['server_name'])) {
                        $settings = $v2node[$key]['tls_settings'];
                        $settings['server_name'] = $parent['tls_settings']['server_name'];
                        $v2node[$key]['tls_settings'] = $settings;
                    }
                }
            }
            if (isset($v2node[$key]['tls_settings'])) {
                $v2node[$key]['tls_settings'] = array_diff_key(
                    $v2node[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($v2node, $key) {
                        return isset($v2node[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($v2node[$key]['encryption_settings'])) {
                if (isset($v2node[$key]['encryption_settings']['private_key'])) {
                    $v2node[$key]['encryption_settings'] = array_diff_key($v2node[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $servers[] = $v2node[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableServers(User $user, bool $withStatus = true)
    {
        $servers = array_merge(
            $this->getAvailableShadowsocks($user),
            $this->getAvailableVmess($user),
            $this->getAvailableTrojan($user),
            $this->getAvailableTuic($user),
            $this->getAvailableHysteria($user),
            $this->getAvailableVless($user),
            $this->getAvailableAnyTLS($user),
            $this->getAvailableV2node($user)
        );
        usort($servers, static fn ($a, $b) => [(int) ($a['sort'] ?? 0), $a['type'], (int) $a['id']]
            <=> [(int) ($b['sort'] ?? 0), $b['type'], (int) $b['id']]);
        $statuses = $withStatus ? $this->serverStatuses($servers) : [];
        foreach ($servers as $index => &$server) {
            if (strpos($server['port'], '-')) {
                $server['mport'] = (string)$server['port'];
            } else {
                $server['port'] = (int)$server['port'];
            }
            if (!$withStatus) {
                continue;
            }
            $status = $statuses[$index] ?? [
                'last_check_at' => 0,
                'last_push_at' => 0,
            ];
            $lastCheckAt = max((int) ($server['last_check_at'] ?? 0), $status['last_check_at']);
            $lastPushAt = $status['last_push_at'];
            $server['last_check_at'] = $lastCheckAt;
            $server['last_push_at'] = $lastPushAt;
            $server['is_online'] = max($lastCheckAt, $lastPushAt) > 0 ? 1 : 0;
            $server['cache_key'] = "{$server['type']}-{$server['id']}-{$server['updated_at']}-{$server['is_online']}";
        }
        unset($server);

        $servers = app(SubscriptionEntryService::class)->apply($servers);
        return array_map([$this, 'publicServerSettings'], $servers);
    }

    private function publicServerSettings(array $server): array
    {
        if (($server['type'] ?? null) === 'v2node' && ($server['protocol'] ?? null) === 'anytls') {
            $server['udp'] = false;
        }
        // This boundary serves user APIs and subscription generators, never the node configuration API.
        if (isset($server['tls_settings'])) {
            $settings = $server['tls_settings'];
            if (is_string($settings)) $settings = json_decode($settings, true);
            if (is_object($settings)) $settings = (array) $settings;
            $server['tls_settings'] = is_array($settings) ? array_diff_key($settings, array_flip([
                'private_key', 'ech_key', 'tls_key', 'dns_env',
                'cert_file', 'key_file', 'provider', 'tls_cert',
            ])) : [];
        }
        if (isset($server['encryption_settings'])) {
            $settings = $server['encryption_settings'];
            if (is_string($settings)) $settings = json_decode($settings, true);
            if (is_object($settings)) $settings = (array) $settings;
            $server['encryption_settings'] = is_array($settings)
                ? array_diff_key($settings, ['private_key' => true]) : [];
        }
        unset($server['ravel_masquerade'], $server['ravel_websocket_masquerade']);
        return $server;
    }

    public function getAvailableUsers($groupId)
    {
        $users = User::whereIn('group_id', $groupId)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->orderBy('id')
            ->get();
        return $users;
    }

    public function log(int $userId, int $serverId, int $u, int $d, float $rate, string $method)
    {
        if (($u + $d) < 10240) return true;
        $timestamp = strtotime(date('Y-m-d'));
        $serverLog = ServerLog::where('log_at', '>=', $timestamp)
            ->where('log_at', '<', $timestamp + 3600)
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('rate', $rate)
            ->where('method', $method)
            ->first();
        if ($serverLog) {
            try {
                $serverLog->increment('u', $u);
                $serverLog->increment('d', $d);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            $serverLog = new ServerLog();
            $serverLog->user_id = $userId;
            $serverLog->server_id = $serverId;
            $serverLog->u = $u;
            $serverLog->d = $d;
            $serverLog->rate = $rate;
            $serverLog->log_at = $timestamp;
            $serverLog->method = $method;
            return $serverLog->save();
        }
    }

    public function getAllShadowsocks()
    {
        $servers = ServerShadowsocks::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'shadowsocks';
        }
        return $servers;
    }

    public function getAllVMess()
    {
        $servers = ServerVmess::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vmess';
        }
        return $servers;
    }

    public function getAllVLess()
    {
        $servers = ServerVless::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vless';
        }
        return $servers;
    }

    public function getAllTrojan()
    {
        $servers = ServerTrojan::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'trojan';
        }
        return $servers;
    }

    public function getAllTuic()
    {
        $servers = ServerTuic::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'tuic';
        }
        return $servers;
    }

    public function getAllHysteria()
    {
        $servers = ServerHysteria::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'hysteria';
        }
        return $servers;
    }

    public function getAllAnyTLS()
    {
        $servers = ServerAnytls::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'anytls';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }
        }
        return $servers;
    }

    public function getAllV2node()
    {
        $servers = ServerV2node::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        if (!$servers) return [];

        $apiHost = $this->panelSetting('server_api_url')
            ?: $this->panelSetting('app_url')
            ?: config('app.url');
        $defaultApiKey = $this->panelSetting('server_token', '');
        $apiHost = rtrim((string) $apiHost, '/');
        $machineIds = array_values(array_unique(array_filter(
            array_map(static fn ($server) => (int) ($server['machine_id'] ?? 0), $servers),
            static fn ($id) => $id > 0,
        )));
        $machineKeys = [];
        foreach (array_chunk($machineIds, 500) as $ids) {
            foreach (\App\Models\Machine::query()->select(['id', 'api_token'])->whereIn('id', $ids)->get() as $machine) {
                if (trim((string) $machine->api_token) !== '') {
                    $machineKeys[(int) $machine->id] = MachineNodeAuthService::credential($machine);
                }
            }
        }
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'v2node';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }

            $apiKey = $machineKeys[(int) ($v['machine_id'] ?? 0)] ?? $defaultApiKey;
            $nodeId = (int) $v['id'];
            $apiKey = (string) $apiKey;

            if ($apiHost !== '' && $apiKey !== '') {
                $apiHostArg = escapeshellarg($apiHost);
                $apiKeyArg = escapeshellarg($apiKey);
                $servers[$k]['install_command'] = sprintf(
                    'wget -N https://raw.githubusercontent.com/YeJianbo/v2node/main/script/install.sh && bash install.sh --api-host %s --node-id %d --api-key %s',
                    $apiHostArg,
                    $nodeId,
                    $apiKeyArg
                );
            } else {
                $servers[$k]['install_command'] = '';
                $servers[$k]['install_command_missing_reason'] = '请先在系统设置中配置站点 URL 和通讯密钥';
            }
        }
        return $servers;
    }

    private function mergeData(&$servers)
    {
        $statuses = $this->serverStatuses($servers);
        foreach ($servers as $k => $v) {
            $status = $statuses[$k] ?? [
                'online' => 0,
                'last_check_at' => 0,
                'last_push_at' => 0,
            ];
            $online = $status['online'];
            $lastCheckAt = $status['last_check_at'];
            $lastPushAt = $status['last_push_at'];

            $servers[$k]['online'] = (int) $online;
            $servers[$k]['last_check_at'] = $lastCheckAt;
            $servers[$k]['last_push_at'] = $lastPushAt;
            $lastActiveAt = max($lastCheckAt, $lastPushAt);
            $servers[$k]['is_online'] = $lastActiveAt > 0 ? 1 : 0;
            if (!$servers[$k]['is_online']) {
                $servers[$k]['available_status'] = 0;
            } else if (!$lastPushAt) {
                $servers[$k]['available_status'] = 1;
            } else {
                $servers[$k]['available_status'] = 2;
            }
        }
    }

    public function getAllServers()
    {
        $servers = array_merge(
            $this->getAllShadowsocks(),
            $this->getAllVMess(),
            $this->getAllTrojan(),
            $this->getAllTuic(),
            $this->getAllHysteria(),
            $this->getAllVLess(),
            $this->getAllAnyTLS(),
            $this->getAllV2node()
        );
        $this->mergeData($servers);
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        return $servers;
    }

    public function getNodeStatusSummary(): array
    {
        $servers = [];
        foreach (['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            foreach ($this->availableServerCollection($type) as $server) {
                $servers[] = ['type' => $type, 'id' => $server['id'], 'parent_id' => $server['parent_id'] ?? null];
            }
        }
        $online = 0;
        foreach ($this->serverStatuses($servers, false) as $status) {
            if ($status['last_check_at'] > 0 || $status['last_push_at'] > 0) $online++;
        }
        return ['total' => count($servers), 'online' => $online];
    }

    public function getNodeStatusDetails(): array
    {
        $servers = [];
        foreach (['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            foreach ($this->availableServerCollection($type) as $server) {
                $servers[] = [
                    'type' => $type,
                    'id' => $server['id'],
                    'parent_id' => $server['parent_id'] ?? null,
                    'key' => $type . ':' . $server['id'],
                    'name' => (string) ($server['name'] ?? ''),
                    'protocol' => (string) ($server['protocol'] ?? $type),
                    'host' => (string) ($server['host'] ?? ''),
                    'port' => $server['port'] ?? '',
                    'show' => (bool) ($server['show'] ?? false),
                    'sort' => (int) ($server['sort'] ?? 0),
                ];
            }
        }

        // Match node management: children inherit their same-protocol parent's heartbeat.
        $statuses = $this->serverStatuses($servers, false);
        foreach ($servers as $index => &$server) {
            $status = $statuses[$index];
            $server['last_check_at'] = $status['last_check_at'];
            $server['last_push_at'] = $status['last_push_at'];
            $server['last_active_at'] = max($status['last_check_at'], $status['last_push_at']);
            $server['is_online'] = $server['last_active_at'] > 0;
        }
        unset($server);
        usort($servers, static fn ($a, $b) => [$a['sort'], $a['type'], $a['id']] <=> [$b['sort'], $b['type'], $b['id']]);
        return $servers;
    }

    public function getPresentationServers(?array $references = null): array
    {
        $models = [
            'shadowsocks' => ServerShadowsocks::class, 'vmess' => ServerVmess::class,
            'trojan' => ServerTrojan::class, 'tuic' => ServerTuic::class,
            'hysteria' => ServerHysteria::class, 'vless' => ServerVless::class,
            'anytls' => ServerAnytls::class, 'v2node' => ServerV2node::class,
        ];
        $idsByType = [];
        foreach ($references ?? [] as $reference) {
            $type = (string) ($reference['server_type'] ?? '');
            $id = (int) ($reference['server_id'] ?? 0);
            if (isset($models[$type]) && $id > 0) $idsByType[$type][$id] = $id;
        }
        $servers = [];
        foreach ($models as $type => $model) {
            $batches = $references === null ? [null] : array_chunk(array_values($idsByType[$type] ?? []), 500);
            foreach ($batches as $batch) {
                $query = $model::query();
                if ($batch !== null) $query->whereIn('id', $batch);
                foreach ($query->get(['id', 'name', 'host', 'port', 'sort', 'show']) as $server) {
                    $servers[] = $server->toArray() + ['type' => $type];
                }
            }
        }
        usort($servers, static fn ($a, $b) => [$a['sort'], $a['type'], $a['id']] <=> [$b['sort'], $b['type'], $b['id']]);
        return $servers;
    }

    public function getRoutes(array $routeIds)
    {
        $routeIds = array_map('intval', $routeIds);
        $order = implode(',', $routeIds);
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->orderByRaw("FIELD(id, $order)")
            ->get();
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array)) $routes[$k]['match'] = $array;
        }
        return $routes;
    }

    public function getServer($serverId, $serverType)
    {
        switch ($serverType) {
            case 'v2node':
                return ServerV2node::find($serverId);
            case 'vmess':
                return ServerVmess::find($serverId);
            case 'shadowsocks':
                return ServerShadowsocks::find($serverId);
            case 'trojan':
                return ServerTrojan::find($serverId);
            case 'tuic':
                return ServerTuic::find($serverId);
            case 'hysteria':
                return ServerHysteria::find($serverId);
            case 'vless':
                return ServerVless::find($serverId);
            case 'anytls':
                return ServerAnytls::find($serverId);
            default:
                return false;
        }
    }
}
