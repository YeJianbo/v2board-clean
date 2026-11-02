<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerV2node;
use App\Services\NodeSyncService;
use App\Support\RavelConfig;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use ParagonIE_Sodium_Compat as SodiumCompat;
use App\Utils\Helper;

class V2nodeController extends Controller
{
    private const DEFAULT_TLS_SERVER_NAME = 'genshin.hoyoverse.com';

    private function notifyMachineNodesChanged(array $machineIds): void
    {
        NodeSyncService::scheduleMachineNodesChanged($machineIds);
    }

    private function notifySavedMachineNodes(ServerV2node $before, ServerV2node $after): void
    {
        $this->notifyMachineNodesChanged([
            (int) ($before->machine_id ?: 0), (int) ($before->relay_machine_id ?: 0),
            (int) ($after->machine_id ?: 0), (int) ($after->relay_machine_id ?: 0),
        ]);
    }

    public function save(Request $request)
    {
        $metadata = $this->validateServerSaveRequest($request);
        $params = $request->validate(array_merge([
            'group_id' => 'required',
            'route_id' => 'nullable|array',
            'name' => 'required',
            'parent_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer|exists:v2_machine,id',
            'relay_machine_id' => 'nullable|integer|exists:v2_machine,id',
            'host' => 'required',
            'listen_ip' => 'nullable',
            'port' => 'required',
            'server_port' => 'required',
            'protocol' => 'required|in:shadowsocks,vmess,vless,trojan,tuic,hysteria2,anytls,ravel',
            'tls' => 'required|in:0,1,2',
            'tls_settings' => 'nullable|array',
            'tls_settings.server_name' => 'nullable|required_if:protocol,ravel|string|max:255',
            'flow' => 'nullable|in:xtls-rprx-vision',
            'network' => 'required|in:tcp,ws,grpc,http,httpupgrade,xhttp',
            'network_settings' => 'nullable|array',
            'encryption' => 'nullable',
            'encryption_settings' => 'nullable|array',
            'disable_sni' => 'required|in:0,1',
            'udp_relay_mode' => 'nullable',
            'zero_rtt_handshake' => 'required|in:0,1',
            'congestion_control' => 'nullable',
            'cipher' => 'nullable',
            'up_mbps' => 'nullable|numeric',
            'down_mbps' => 'nullable|numeric',
            'obfs' => 'nullable',
            'obfs_password' => 'nullable',
            'padding_scheme' => 'nullable',
            'tags' => 'nullable|array',
            'rate' => 'required',
            'show' => 'nullable|in:0,1',
            'sort' => 'nullable'
        ], RavelConfig::rules()));
        $params = $this->serverSaveParameters($params);
        if ($params['protocol'] === 'ravel') {
            if ((int) $params['tls'] !== 1 || $params['network'] !== 'tcp' || !empty($params['route_id'])) {
                throw ValidationException::withMessages(['protocol' => 'Ravel 需要 TLS、TCP 入口，当前不支持节点路由规则']);
            }
            if (empty($params['tls_settings']['server_name'])) {
                throw ValidationException::withMessages(['tls_settings.server_name' => 'Ravel 需要明确的 TLS 服务器名称']);
            }
            if (!in_array($params['tls_settings']['allow_insecure'] ?? null, [0, '0', false, null], true)) {
                throw ValidationException::withMessages(['tls_settings.allow_insecure' => 'Ravel 订阅必须校验 TLS 证书；自签证书请导入可信 CA']);
            }
            $params['tls_settings']['allow_insecure'] = 0;
            $params['ravel_settings'] = RavelConfig::normalize('ravel', $params['ravel_settings'] ?? []);
        }
        if ($params['protocol'] == 'anytls' && (int) $params['tls'] === 0) {
            $params['tls'] = 1;
        }
        if (in_array($params['protocol'], ['hysteria2', 'trojan', 'tuic'])) {
            $params['tls'] = 1;
        }
        $params['tls_settings'] = $this->normalizeTlsSettingsForProtocol(
            $params['protocol'],
            $params['tls_settings'] ?? [],
            (int) ($params['tls'] ?? 0)
        );
        if (isset($params['tls']) && (int)$params['tls'] === 2) {
            $params['tls_settings'] = Helper::normalizeRealityKeyPair($params['tls_settings']);
            if (empty($params['tls_settings']['short_id'])) {
                $params['tls_settings']['short_id'] = substr(sha1($params['tls_settings']['private_key']), 0, 8);
            }
            if (empty($params['tls_settings']['server_port'])) {
                $params['tls_settings']['server_port'] = "443";
            } else {
                $params['tls_settings']['server_port'] = (string) $params['tls_settings']['server_port'];
            }
        }
        if (isset($params['tls_settings']) && !empty($params['tls_settings']['ech']) && $params['tls_settings']['ech'] === 'custom') {
            if (empty($params['tls_settings']['ech_server_name'])) {
                $params['tls_settings']['ech'] = '';
            } else {
                $outerSni = $params['tls_settings']['ech_server_name'];
                if (empty($params['tls_settings']['ech_key']) || empty($params['tls_settings']['ech_config'])) {
                    $echPair = Helper::generateEchKeyPair($outerSni);
                    if (empty($params['tls_settings']['ech_key'])) {
                        $params['tls_settings']['ech_key'] = $echPair['ech_key'];
                    }
                    if (empty($params['tls_settings']['ech_config'])) {
                        $params['tls_settings']['ech_config'] = $echPair['ech_config'];
                    }
                }
            }
        }
        if (isset($params['network_settings'])) {
            $ns = $params['network_settings'];
            if (isset($ns['acceptProxyProtocol'])) {
                $ns['acceptProxyProtocol'] = filter_var($ns['acceptProxyProtocol'], FILTER_VALIDATE_BOOLEAN);
            }
            $params['network_settings'] = $ns;
        }
        if ($params['network'] != 'tcp' && isset($params['encryption']) && $params['encryption'] != 'mlkem768x25519plus') {
            $params['flow'] = null;
        }
        if ($params['network'] == 'xhttp' && isset($params['network_settings'])) {
            $ns = $params['network_settings'];
            if (isset($ns['extra']) && is_array($ns['extra'])) {
                $extra = $ns['extra'];
                if (isset($extra['xPaddingObfsMode'])) {
                    $extra['xPaddingObfsMode'] = filter_var($extra['xPaddingObfsMode'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($extra['noGRPCHeader'])) {
                    $extra['noGRPCHeader'] = filter_var($extra['noGRPCHeader'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($extra['noSSEHeader'])) {
                    $extra['noSSEHeader'] = filter_var($extra['noSSEHeader'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($extra['scMaxBufferedPosts'])) {
                    $extra['scMaxBufferedPosts'] = (int)$extra['scMaxBufferedPosts'];
                }
                if (isset($extra['xmux']) && is_array($extra['xmux'])) {
                    $xmux = $extra['xmux'];
                    if (isset($xmux['hKeepAlivePeriod'])) {
                        $xmux['hKeepAlivePeriod'] = (int)$xmux['hKeepAlivePeriod'];
                    }
                    $extra['xmux'] = $xmux;
                }
                if (isset($extra['downloadSettings']) && is_array($extra['downloadSettings'])) {
                    $downloadSettings = $extra['downloadSettings'];
                    if (isset($downloadSettings['port'])) {
                        $downloadSettings['port'] = (int)$downloadSettings['port'];
                    }
                    $extra['downloadSettings'] = $downloadSettings;
                }
                $ns['extra'] = $extra;
            }
            $params['network_settings'] = $ns;
        }
        if (isset($params['encryption']) && $params['encryption'] == 'mlkem768x25519plus') {
            $keyPair = SodiumCompat::crypto_box_keypair();
            $params['encryption_settings'] = $params['encryption_settings'] ?? [];
            if (!isset($params['encryption_settings']['mode'])) {
                $params['encryption_settings']['mode'] = 'native';
            }
            if (isset($params['encryption_settings']['rtt'])) {
                if ($params['encryption_settings']['rtt'] == '1rtt') {
                    $params['encryption_settings']['ticket'] = '0s';
                }
            } else {
                $params['encryption_settings']['rtt'] = '0rtt';
                $params['encryption_settings']['ticket'] = '600s';
            }
            if (empty($params['encryption_settings']['private_key'])) {
                $params['encryption_settings']['private_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_secretkey($keyPair));
            }
            if (empty($params['encryption_settings']['password'])) {
                $params['encryption_settings']['password'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_publickey($keyPair));
            }
        }

        if (isset($params['padding_scheme'])) {
            $params['padding_scheme'] = json_decode($params['padding_scheme']);
        }

        if (!isset($params['up_mbps'])) {
            $params['up_mbps'] = 0;
        }
        if (!isset($params['down_mbps'])) {
            $params['down_mbps'] = 0;
        }

        if(isset($params['obfs'])) {
            if(!isset($params['obfs_password']))  $params['obfs_password'] = Helper::getServerKey($request->input('created_at'), 16);
        } else {
            $params['obfs_password'] = null;
        }

        if($params['protocol'] == 'shadowsocks' && !isset($params['cipher'])) {
            $params['cipher'] = 'aes-128-gcm';
        }
        if (array_key_exists('machine_id', $params)) {
            $params['machine_id'] = !empty($params['machine_id']) ? (int) $params['machine_id'] : null;
        }
        if (array_key_exists('relay_machine_id', $params)) {
            $params['relay_machine_id'] = !empty($params['relay_machine_id']) ? (int) $params['relay_machine_id'] : null;
        }
        if (!empty($params['parent_id'])) {
            $parentServer = ServerV2node::find((int) $params['parent_id']);
            if (!$parentServer) {
                abort(422, '父节点不存在');
            }

            $parentMachineId = (int) ($parentServer->machine_id ?: 0);
            if ($parentMachineId <= 0) {
                abort(422, '父节点未绑定在线机器，无法创建中转子节点');
            }

            $params['machine_id'] = $parentMachineId;
            if (!empty($params['relay_machine_id']) && (int) $params['relay_machine_id'] === $parentMachineId) {
                abort(422, '中转服务器不能与父节点落地机相同');
            }
        }
        $inputMachineId = array_key_exists('machine_id', $params) ? (int) ($params['machine_id'] ?: 0) : 0;
        if (
            !empty($params['relay_machine_id']) &&
            $inputMachineId > 0 &&
            (int) $params['relay_machine_id'] === $inputMachineId
        ) {
            $params['relay_machine_id'] = null;
        }

        if ($request->input('id')) {
            $server = ServerV2node::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server = $this->saveServerWithMetadata($server, $params, 'v2node', $metadata,
                    fn ($before, $after) => $this->notifySavedMachineNodes($before, $after));
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                \Log::error($e);
                abort(500, '保存失败');
            }

            return response([
                'data' => true
            ]);
        }

        try {
            $server = $this->saveServerWithMetadata(new ServerV2node(), $params, 'v2node', $metadata,
                fn ($before, $after) => $this->notifySavedMachineNodes($before, $after));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function normalizeTlsSettingsForProtocol(string $protocol, array $settings, int $tlsMode = 0): array
    {
        if (!in_array($protocol, ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess'], true)) {
            return $settings;
        }

        if (($tlsMode === 1 || $tlsMode === 2) && empty($settings['server_name'])) {
            $settings['server_name'] = self::DEFAULT_TLS_SERVER_NAME;
        }

        if (
            $tlsMode === 1 &&
            isset($settings['cert_mode']) &&
            strtolower((string) $settings['cert_mode']) === 'self'
        ) {
            $settings['allow_insecure'] = 1;
        }

        if ($tlsMode === 2 && empty($settings['server_port'])) {
            $settings['server_port'] = "443";
        } elseif (array_key_exists('server_port', $settings) && $settings['server_port'] !== null && $settings['server_port'] !== '') {
            $settings['server_port'] = (string) $settings['server_port'];
        }

        return $settings;
    }

    public function drop(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        if ($request->input('id')) {
            $server = ServerV2node::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        try {
            $server = $this->deleteServerWithMetadata($server, 'v2node');
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '删除失败');
        }
        $machineId = (int) ($server->machine_id ?: 0);
        $relayMachineId = (int) ($server->relay_machine_id ?: 0);
        $result = true;
        $this->notifyMachineNodesChanged([$machineId, $relayMachineId]);
        return response([
            'data' => $result
        ]);
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'show' => 'nullable|in:0,1',
            'machine_id' => 'nullable|integer|exists:v2_machine,id',
            'relay_machine_id' => 'nullable|integer|exists:v2_machine,id',
        ]);

        $server = ServerV2node::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        $originalMachineId = (int) ($server->machine_id ?: 0);
        if (array_key_exists('machine_id', $params)) {
            $params['machine_id'] = !empty($params['machine_id']) ? (int) $params['machine_id'] : null;
        }
        if (array_key_exists('relay_machine_id', $params)) {
            $params['relay_machine_id'] = !empty($params['relay_machine_id']) ? (int) $params['relay_machine_id'] : null;
        }
        if ((int) ($server->parent_id ?: 0) > 0) {
            $parentServer = ServerV2node::find((int) $server->parent_id);
            if (!$parentServer) {
                abort(422, '父节点不存在');
            }

            $parentMachineId = (int) ($parentServer->machine_id ?: 0);
            if ($parentMachineId <= 0) {
                abort(422, '父节点未绑定在线机器，无法更新中转子节点');
            }

            $params['machine_id'] = $parentMachineId;
            $nextRelayMachineId = !empty($params['relay_machine_id'])
                ? (int) $params['relay_machine_id']
                : (int) ($server->relay_machine_id ?: 0);
            if ($nextRelayMachineId > 0 && $nextRelayMachineId === $parentMachineId) {
                abort(422, '中转服务器不能与父节点落地机相同');
            }
        }
        $nextMachineId = array_key_exists('machine_id', $params)
            ? (int) ($params['machine_id'] ?: 0)
            : $originalMachineId;
        if (
            !empty($params['relay_machine_id']) &&
            $nextMachineId > 0 &&
            (int) $params['relay_machine_id'] === $nextMachineId
        ) {
            $params['relay_machine_id'] = null;
        }
        try {
            $server = $this->saveServerWithMetadata($server, $params, 'v2node', null,
                fn ($before, $after) => $this->notifySavedMachineNodes($before, $after));
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $server = ServerV2node::find($request->input('id'));
        if (!$server) {
            abort(500, '服务器不存在');
        }

        try {
            $copiedServer = $this->copyServerWithMetadata($server, 'v2node', [
                'show' => 0, 'machine_id' => null, 'relay_machine_id' => null,
            ], true);
        } catch (\Throwable $e) {
            \Log::error($e);
            abort(500, '复制失败');
        }

        $this->notifyMachineNodesChanged([
            (int) ($copiedServer->machine_id ?: 0),
            (int) ($copiedServer->relay_machine_id ?: 0),
        ]);

        return response([
            'data' => true
        ]);
    }
}
