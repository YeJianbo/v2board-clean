<?php

namespace App\Protocols;

use App\Services\RavelCredentialService;
use App\Support\RavelConfig;

/** Subscription for the PanelCore mihomo core's protocol extensions. */
class PanelCoreMihomo extends ClashVerge
{
    public $flag = 'panelcore';
    private array $ravelCredentials = [];

    protected function buildRavel($user, array $server): ?array
    {
        $group = (string) ($server['ravel_gateway_group'] ?? '');
        $groups = array_values(array_map('intval', (array) ($server['group_id'] ?? [])));
        sort($groups);
        $cacheKey = $group . ':' . json_encode($groups);
        if (!array_key_exists($cacheKey, $this->ravelCredentials)) {
            $this->ravelCredentials[$cacheKey] = app(RavelCredentialService::class)
                ->forUsers([$user], $group, $groups)[$user->id] ?? null;
        }
        $credential = $this->ravelCredentials[$cacheKey];
        if ($credential === null) return null;
        $settings = RavelConfig::normalize('ravel', $server['ravel_settings'] ?? []);
        $proxy = [
            'name' => $server['name'], 'type' => 'ravel',
            'server' => $server['host'], 'port' => (int) $server['port'],
            'sni' => (string) ($server['tls_settings']['server_name'] ?? ''),
            'skip-cert-verify' => false, 'udp' => false,
            'authority' => $server['ravel_authority'], 'path' => $server['ravel_path'],
            'credential-id' => $credential['credential_id'],
            'capability-key' => $credential['capability_key'],
            'key-version' => $credential['key_version'], 'policy-id' => $credential['policy_id'],
            'gateway-group' => $group, 'not-before' => $credential['not_before'], 'not-after' => $credential['not_after'],
            'handshake-timeout-seconds' => $settings['handshake_timeout'],
            'session-max-streams' => $settings['max_streams'],
            'session-max-bytes' => $settings['session_max_bytes'],
            'drain-timeout-seconds' => $settings['drain_timeout'],
            'max-message-size' => $settings['max_message'],
            'max-frame-receive' => $settings['max_frame_receive'],
            'initial-connection-window' => $settings['connection_window'],
            'initial-stream-window' => $settings['stream_window'],
            'chunk-min-size' => $settings['chunk_min'], 'chunk-max-size' => $settings['chunk_max'],
            'chunk-min-delay-ms' => $settings['chunk_min_delay'], 'chunk-max-delay-ms' => $settings['chunk_max_delay'],
        ];
        $caCertificate = $server['tls_settings']['ravel_ca_certificate'] ?? null;
        if ($caCertificate !== null && $caCertificate !== '') {
            $certificate = is_string($caCertificate) ? @openssl_x509_read($caCertificate) : false;
            if (!$certificate || !openssl_x509_export($certificate, $pem)) {
                throw new \RuntimeException('Invalid Ravel CA certificate');
            }
            // Export only the public certificate, even if the input contained extra PEM blocks.
            $proxy['certificate'] = $pem;
        }
        return $proxy;
    }

    public function handle()
    {
        return parent::handle()->header('X-PanelCore-Mihomo', '1');
    }

    protected function shouldBuildAnyTLS(array $server, array $clientInfo): bool
    {
        return true;
    }

    public static function buildAnyTLS($password, $server)
    {
        $proxy = parent::buildAnyTLS($password, $server);
        $settings = $server['tls_settings'] ?? [];
        $proxy['client-fingerprint'] = !empty($settings['fingerprint']) ? $settings['fingerprint'] : 'chrome';

        // The public server boundary disables v2node AnyTLS UDP for legacy clients.
        // Capability v1 uses the custom core's tested UoT implementation.
        if (($server['protocol'] ?? null) === 'anytls') {
            $proxy['udp'] = true;
        }

        if (self::isAnyTLSReality($server)) {
            $proxy['reality-opts'] = [
                'public-key' => $settings['public_key'] ?? '',
                'short-id' => $settings['short_id'] ?? '',
            ];
            $proxy['skip-cert-verify'] = false;
            // Reality authenticates with its public key, not a certificate pin.
            unset($proxy['fingerprint']);
        }

        return $proxy;
    }
}
