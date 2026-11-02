<?php

namespace App\Protocols;

use App\Services\RavelCredentialService;
use App\Support\RavelConfig;

class RavelJson
{
    private $user;
    private array $servers;

    public function __construct($user, array $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $nodes = [];
        $credentials = [];
        foreach ($this->servers as $server) {
            if (($server['type'] ?? '') !== 'v2node' || ($server['protocol'] ?? '') !== 'ravel') continue;
            $group = (string) $server['ravel_gateway_group'];
            $groups = array_values(array_map('intval', (array) ($server['group_id'] ?? [])));
            sort($groups);
            $key = $group . ':' . json_encode($groups);
            if (!array_key_exists($key, $credentials)) {
                $credentials[$key] = app(RavelCredentialService::class)->forUsers([$this->user], $group, $groups)[$this->user->id] ?? null;
            }
            if ($credentials[$key] === null) continue;
            $settings = RavelConfig::normalize('ravel', $server['ravel_settings'] ?? []);
            $clientSettings = array_intersect_key($settings, array_flip([
                'stream_window', 'connection_window', 'max_message', 'max_frame_receive',
                'chunk_min', 'chunk_max', 'chunk_min_delay', 'chunk_max_delay', 'handshake_timeout',
            ]));
            $clientSettings['session_max_streams'] = $settings['max_streams'];
            $nodes[] = [
                'name' => $server['name'], 'server' => $server['host'], 'port' => (int) $server['port'],
                'sni' => (string) ($server['tls_settings']['server_name'] ?? ''),
                'insecure' => false,
                'authority' => $server['ravel_authority'], 'path' => $server['ravel_path'], 'gateway_group' => $group,
                'credentials' => [$credentials[$key]], 'settings' => $clientSettings,
            ];
        }
        return response()->json(['schema' => 'v2board.ravel.subscription', 'schema_version' => 1, 'nodes' => $nodes])
            ->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }
}
