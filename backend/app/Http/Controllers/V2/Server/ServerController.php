<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\NodeCertificateSyncService;
use Illuminate\Http\Request;
use App\Utils\Helper;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    private const DEFAULT_TLS_SERVER_NAME = 'genshin.hoyoverse.com';
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    private function objectOrEmpty($value)
    {
        if (empty($value)) {
            return (object) [];
        }

        return $value;
    }

    private function normalizeSettingsForV2node($value, string $protocol = '', int $tlsMode = 0)
    {
        if (empty($value)) {
            $value = [];
        }

        $settings = is_array($value) ? $value : (array) $value;
        if (
            ($tlsMode === 1 || $tlsMode === 2) &&
            in_array($protocol, ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess'], true) &&
            empty($settings['server_name'])
        ) {
            $settings['server_name'] = self::DEFAULT_TLS_SERVER_NAME;
        }
        if (array_key_exists('server_port', $settings) && $settings['server_port'] !== null) {
            $settings['server_port'] = (string) $settings['server_port'];
        }

        return $settings;
    }

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

    public function __construct(Request $request)
    {
        $token = \App\Services\MachineNodeAuthService::token($request);

        // token 为空（业务失败，不抛异常）
        if (empty($token)) {
            response()->json([
                'status' => 'fail',
                'message' => 'token is null'
            ], 200)->send();
            exit;
        }

        $this->nodeId = $request->input('node_id');
        $this->serverService = new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, "v2node");

        // 节点不存在
        if (!$this->nodeInfo) {
            response()->json([
                'status' => 'fail',
                'message' => 'server is not exist'
            ], 200)->send();
            exit;
        }
        \App\Services\MachineNodeAuthService::authorize($request, $this->nodeInfo, fn () => (string) $this->panelSetting('server_token', ''));
    }

    // 后端获取配置
    public function config(Request $request)
    {
        if ($this->nodeInfo->protocol === 'ravel' && !\App\Support\RavelConfig::supportsNodeRequest($request)) {
            abort(409, '此节点需要支持 Ravel/1 的节点客户端');
        }
        Cache::put(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $this->nodeInfo->id), time(), 3600);

        $response = [
            'listen_ip' => $this->nodeInfo->listen_ip,
            'server_port' => $this->nodeInfo->server_port,
            'network' => $this->nodeInfo->network,
            'network_settings' => $this->objectOrEmpty($this->nodeInfo->network_settings),
            'protocol' => $this->nodeInfo->protocol,
            'tls' => $this->nodeInfo->tls,
            'tls_settings' => $this->normalizeSettingsForV2node(
                $this->nodeInfo->tls_settings,
                (string) $this->nodeInfo->protocol,
                (int) $this->nodeInfo->tls
            ),
            'encryption' => $this->nodeInfo->encryption,
            'encryption_settings' => $this->objectOrEmpty($this->nodeInfo->encryption_settings),
            'flow' => $this->nodeInfo->flow,
            'cipher' => $this->nodeInfo->cipher,
            'congestion_control' => $this->nodeInfo->congestion_control,
            'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
            'up_mbps' => $this->nodeInfo->up_mbps,
            'down_mbps' => $this->nodeInfo->down_mbps,
            'obfs' => $this->nodeInfo->obfs,
            'obfs_password' => $this->nodeInfo->obfs_password,
            'padding_scheme' => $this->nodeInfo->padding_scheme
        ];

        if ($this->nodeInfo->protocol === 'ravel') {
            $response['ravel'] = \App\Support\RavelConfig::nodePayload($this->nodeInfo);
        }

        if (NodeCertificateSyncService::supportsRequest($request)) {
            $response['tls_settings'] = NodeCertificateSyncService::runtimeTlsSettings(
                $response['tls_settings'], (string) $this->nodeInfo->protocol, (int) $this->nodeInfo->tls
            );
        }
        $certificateSyncToken = app(NodeCertificateSyncService::class)->tokenForConfig($request, $this->nodeInfo);
        if ($certificateSyncToken !== null) {
            $response['certificate_sync_token'] = $certificateSyncToken;
        }

        if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
        }

        if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
        }

        if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
            $response['ignore_client_bandwidth'] = true;
        } else {
            $response['ignore_client_bandwidth'] = false;
        }

        $response['base_config'] = [
            'domain_traffic_enable' => app(\App\Services\DomainTrafficPolicy::class)->enabled((int) $this->nodeId, (int) ($this->nodeInfo->machine_id ?? 0)),
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60),
            'node_report_min_traffic' => (int)config('v2board.server_node_report_min_traffic', 0),
            'device_online_min_traffic' => (int)config('v2board.server_device_online_min_traffic', 0)
        ];

        if ($this->nodeInfo['route_id']) {
            $response['routes'] = $this->serverService->getRoutes($this->nodeInfo['route_id']);
        }

        $rsp = json_encode($response);
        $eTag = sha1($rsp);

        // 不使用 abort(304)，避免异常路径
        if ($this->etagMatches($request, $eTag)) {
            return response('', 304)->header('Cache-Control', 'private, no-store')->header('ETag', "\"{$eTag}\"");
        }

        return response($response)->header('Cache-Control', 'private, no-store')->header('ETag', "\"{$eTag}\"");
    }

    public function certificate(Request $request)
    {
        abort_unless($request->isMethod('POST'), 405, 'Method not allowed');
        $params = $request->validate([
            'certificate' => 'required|string|max:16384',
            'config_token' => 'required|string|regex:/\Acertificate-v1\.[a-f0-9]{64}\z/',
            'private_key' => 'prohibited',
            'tls_key' => 'prohibited',
            'tls_cert' => 'prohibited',
            'tls_settings' => 'prohibited',
            'allow_insecure' => 'prohibited',
            'cert_mode' => 'prohibited',
            'server_name' => 'prohibited',
        ]);
        return response()->json(app(NodeCertificateSyncService::class)->sync(
            $request, (int) $this->nodeId, $params['certificate'], $params['config_token']
        ))->header('Cache-Control', 'no-store');
    }

    public function domainTraffic(Request $request)
    {
        if (!app(\App\Services\DomainTrafficPolicy::class)->enabled((int) $this->nodeId, (int) ($this->nodeInfo->machine_id ?? 0))) {
            return response()->json(['accepted' => true, 'enabled' => false]);
        }
        $batch = $request->validate([
            'batch_id' => 'required|string|regex:/^[a-f0-9]{32}$/',
            'recorded_at' => 'required|integer|min:' . (time() - 30 * 86400) . '|max:' . (time() + 300),
            'records' => 'required|array|max:4096',
            'records.*.domain' => 'required|string|max:253',
            'records.*.upload' => 'required|integer|min:0|max:1000000000000000',
            'records.*.download' => 'required|integer|min:0|max:1000000000000000',
        ]);
        app(\App\Services\DomainTrafficService::class)->ingest((int) $this->nodeId, $batch);
        return response()->json(['accepted' => true]);
    }
}
