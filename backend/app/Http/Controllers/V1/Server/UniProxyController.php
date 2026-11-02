<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MessagePack\Packer;

class UniProxyController extends Controller
{
    private $nodeType;
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    private function panelSetting(string $key, $default = null)
    {
        $configValue = config('v2board.' . $key);
        if ($configValue !== null && $configValue !== '') {
            return $configValue;
        }

        $dbValue = Cache::remember('panel_setting:' . $key, 60, static function () use ($key) {
            return DB::table('v2_settings')->where('name', $key)->value('value');
        });
        if ($dbValue !== null && $dbValue !== '') {
            return $dbValue;
        }

        return $default;
    }

    private function etagMatches(Request $request, string $etag): bool
    {
        $header = (string)$request->header('If-None-Match', '');
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if (trim($candidate, "\"") === $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }

    private function cachedUserPayload(bool $messagePack, bool $overlap): array
    {
        $groupIds = array_values(array_unique(array_map('intval', (array)$this->nodeInfo->group_id)));
        sort($groupIds);
        $format = $messagePack ? 'msgpack' : 'json';
        $ravelGroup = $this->nodeType === 'v2node' && $this->nodeInfo->protocol === 'ravel'
            ? (string) $this->nodeInfo->ravel_gateway_group : null;
        $cacheKey = 'server_user_payload:v5:' . $format . ':' . sha1(json_encode([$groupIds, $ravelGroup, $overlap]));

        return Cache::remember($cacheKey, 15, function () use ($groupIds, $messagePack, $ravelGroup, $overlap) {
            $users = [];
            $available = $this->serverService->getAvailableUsers($groupIds);
            $service = app(\App\Services\RavelCredentialService::class);
            $credentials = $ravelGroup !== null
                ? ($overlap ? $service->forNodeUsers($available, $ravelGroup, $groupIds) : $service->forUsers($available, $ravelGroup, $groupIds)) : [];
            foreach ($available as $user) {
                $entry = array_filter($user->toArray(), static function ($value) {
                    return $value !== null;
                });
                if ($ravelGroup !== null) {
                    if (!isset($credentials[$user->id])) continue;
                    $entry['ravel_credentials'] = $overlap ? $credentials[$user->id] : [$credentials[$user->id]];
                }
                $users[] = $entry;
            }
            $response = ['users' => $users];
            $body = $messagePack
                ? (new Packer())->pack($response)
                : json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $identities = [];
            if ($ravelGroup !== null) {
                foreach ($users as $user) foreach ($user['ravel_credentials'] as $credential) {
                    $identities[] = \App\Services\RavelCredentialSyncService::identity($credential);
                }
            }
            return ['body' => $body, 'etag' => sha1($body), 'ravel_identities' => $identities];
        });
    }

    public function __construct(Request $request)
    {
        $token = \App\Services\MachineNodeAuthService::token($request);
        if (empty($token)) {
            abort(500, 'token is null');
        }
        $this->nodeType = $request->input('node_type');
        if ($this->nodeType === 'ravel') $this->nodeType = 'v2node';
        if ($this->nodeType === 'v2ray') $this->nodeType = 'vmess';
        if ($this->nodeType === 'hysteria2') $this->nodeType = 'hysteria';
        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, $this->nodeType);
        if (!$this->nodeInfo) abort(500, 'server is not exist');
        \App\Services\MachineNodeAuthService::authorize($request, $this->nodeInfo, fn () => (string) $this->panelSetting('server_token', ''));
    }

    // 后端获取用户
    public function user(Request $request)
    {
        if ($this->nodeType === 'v2node' && $this->nodeInfo->protocol === 'ravel' &&
            !\App\Support\RavelConfig::supportsNodeRequest($request)) {
            abort(409, '此节点需要支持 Ravel/1 的节点客户端');
        }
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);
        $overlap = $this->nodeType === 'v2node' && $this->nodeInfo->protocol === 'ravel' &&
            \App\Support\RavelConfig::supportsOverlapRequest($request);
        if ($this->nodeType === 'v2node' && $this->nodeInfo->protocol === 'ravel') {
            Cache::put(\App\Support\RavelConfig::overlapCapabilityKey((int) $this->nodeInfo->id), $overlap, 3600);
            app(\App\Services\RavelCredentialSyncService::class)->acknowledge((int) $this->nodeInfo->id,
                (string) $request->header('X-Ravel-Applied-Users', ''));
        }
        $messagePack = strpos((string)$request->header('X-Response-Format', ''), 'msgpack') !== false;
        $payload = $this->cachedUserPayload($messagePack, $overlap);
        $eTag = $payload['etag'];
        if ($this->nodeType === 'v2node' && $this->nodeInfo->protocol === 'ravel') {
            app(\App\Services\RavelCredentialSyncService::class)->rememberPayload((int) $this->nodeInfo->id, $eTag, $payload['ravel_identities']);
        }
        if ($this->etagMatches($request, $eTag)) {
            return response('', 304)->header('Cache-Control', 'private, no-store')->header('ETag', "\"{$eTag}\"");
        }

        $contentType = $messagePack ? 'application/x-msgpack' : 'application/json; charset=UTF-8';
        return response($payload['body'], 200, ['Content-Type' => $contentType])
            ->header('Cache-Control', 'private, no-store')->header('ETag', "\"{$eTag}\"");
    }

    // 后端提交数据
    public function push(Request $request)
    {
        $data = \App\Support\TrafficReport::read($request);
        $batchId = $request->header('X-Traffic-Batch-ID');
        $userService = new UserService();
        $userService->trafficFetch($this->nodeInfo->toArray(), $this->nodeType, $data, $batchId);

        return response($batchId === null ? ['data' => true] : ['data' => true, 'batch_id' => $batchId]);
    }

    // 后端获取在线数据
    public function alivelist(Request $request)
    {
        $groupIds = array_values(array_unique(array_map('intval', (array) $this->nodeInfo->group_id)));
        sort($groupIds);
        if (!$groupIds) return response()->json(['alive' => (object) []]);
        $cacheKey = 'node_alive_list:v1:' . sha1(json_encode($groupIds));
        $alive = Cache::remember($cacheKey, 60, function () use ($groupIds) {
            $userService = new UserService();
            $users = $userService->getDeviceLimitedUsers($groupIds);

            if ($users->isEmpty()) {
                return [];
            }

            $keys = [];
            $idMap = [];
            foreach ($users as $user) {
                $key = 'ALIVE_IP_USER_' . $user->id;
                $keys[] = $key;
                $idMap[$key] = $user->id;
            }

            $alive = [];
            foreach (array_chunk($keys, 500) as $batch) {
                foreach (Cache::many($batch) as $key => $data) {
                    if (is_array($data) && isset($data['alive_ip'])) {
                        $alive[$idMap[$key]] = $data['alive_ip'];
                    }
                }
            }
            return $alive;
        });
        return response()->json(['alive' => (object) $alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request)
    {
        $data = $request->json()->all();
        if (empty($data)) {
            $data = $_POST;
        }
        if (empty($data)) {
            return response([
                'data' => true
            ]);
        }
        if (!is_array($data)) {
            return response([
                'error' => 'Invalid online data format'
            ], 400);
        }
        $updateAt = time();
        $distinctIps = config('v2board.device_limit_mode', 0) == 1;
        $reports = [];
        foreach ($data as $uid => $ips) {
            $userId = filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if ($userId !== false && is_array($ips)) $reports[$userId] = $ips;
        }
        $groupIds = array_values(array_unique(array_map('intval', (array) $this->nodeInfo->group_id)));
        if ($reports && $groupIds) {
            $reports = app(\App\Services\SubscriptionGraceService::class)->online($reports);
            // Resolve legacy aliases first, then authorize the real owner against this node.
            foreach (array_chunk($reports, 500, true) as $batch) {
                $allowed = array_fill_keys(User::whereIn('id', array_keys($batch))->whereIn('group_id', $groupIds)->pluck('id')->all(), true);
                foreach ($batch as $userId => $ips) {
                    if (!isset($allowed[$userId])) continue;
                    \App\Services\UserOnlineStateService::updateNode($userId, $this->nodeType . $this->nodeId, $ips, $updateAt, $distinctIps);
                }
            }
        }

        return response([
            'data' => true
        ]);
    }

    // 后端获取配置
    public function config(Request $request)
    {
        Cache::put(CacheKey::get('SERVER_' . strtoupper($this->nodeType) . '_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);

        switch ($this->nodeType) {
            case 'shadowsocks':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'cipher' => $this->nodeInfo->cipher,
                    'obfs' => $this->nodeInfo->obfs,
                    'obfs_settings' => $this->nodeInfo->obfs_settings
                ];

                if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
                }
                if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
                    $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
                }
                break;
            case 'vmess':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->networkSettings,
                    'tls' => $this->nodeInfo->tls
                ];
                break;
            case 'vless':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'tls' => $this->nodeInfo->tls,
                    'flow' => $this->nodeInfo->flow,
                    'tls_settings' => $this->nodeInfo->tls_settings,
                    'encryption' => $this->nodeInfo->encryption,
                    'encryption_settings' => $this->nodeInfo->encryption_settings
                ];
                break;
            case 'trojan':
                $response = [
                    'host' => $this->nodeInfo->host,
                    'network' => $this->nodeInfo->network,
                    'networkSettings' => $this->nodeInfo->network_settings,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                ];
                break;
            case 'tuic':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'congestion_control' => $this->nodeInfo->congestion_control,
                    'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
                ];
                break;
            case 'hysteria':
                $response = [
                    'version' => $this->nodeInfo->version,
                    'host' => $this->nodeInfo->host,
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'up_mbps' => $this->nodeInfo->up_mbps,
                    'down_mbps' => $this->nodeInfo->down_mbps
                ];
                if ($this->nodeInfo->version == 1) {
                    $response['obfs'] = $this->nodeInfo->obfs_password ?? null;
                } elseif ($this->nodeInfo->version == 2) {
                    if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
                        $response['ignore_client_bandwidth'] = true;
                    } else {
                        $response['ignore_client_bandwidth'] = false;
                    }
                    $response['obfs'] = $this->nodeInfo->obfs ?? null;
                    $response['obfs-password'] = $this->nodeInfo->obfs_password ?? null;
                }
                break;
            case 'anytls':
                $response = [
                    'server_port' => $this->nodeInfo->server_port,
                    'server_name' => $this->nodeInfo->server_name,
                    'padding_scheme' => $this->nodeInfo->padding_scheme
                ];
                break;
        }
        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60)
        ];
        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }
        $eTag = sha1(json_encode($response));
        if ($this->etagMatches($request, $eTag)) {
            return response('', 304)->header('ETag', "\"{$eTag}\"");
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
