<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\ServerV2node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class NodeCertificateSyncService
{
    private const PROTOCOLS = ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess'];
    private const PIN_FIELDS = ['certificate_fingerprint', 'certificate_public_key_sha256'];
    private const DEFAULT_SERVER_NAME = 'genshin.hoyoverse.com';

    public static function supportsRequest(Request $request): bool
    {
        return (bool) preg_match('~(?:^|\s)capability/node_certificate_sync_v1(?:\s|$)~', (string) $request->header('User-Agent'));
    }

    public static function runtimeTlsSettings(array $settings, string $protocol, int $tls): array
    {
        if ($tls === 1 && in_array($protocol, self::PROTOCOLS, true) && ($settings['cert_mode'] ?? '') === 'self') {
            return array_diff_key($settings, array_flip(self::PIN_FIELDS));
        }
        return $settings;
    }

    public function tokenForConfig(Request $request, ServerV2node $node): ?string
    {
        if (!self::supportsRequest($request) || !$this->eligible($node)
            || !str_starts_with(MachineNodeAuthService::token($request), 'machine-v1.')) {
            return null;
        }
        MachineNodeAuthService::authorize($request, $node, '');
        return $this->revision($node);
    }

    public function sync(Request $request, int $nodeId, string $certificate, string $configToken): array
    {
        abort_unless($request->isSecure(), 403, 'Certificate sync requires HTTPS');
        abort_unless(str_starts_with(MachineNodeAuthService::token($request), 'machine-v1.'), 401, 'Machine-scoped credential required');

        return DB::transaction(function () use ($request, $nodeId, $certificate, $configToken) {
            $node = ServerV2node::query()->whereKey($nodeId)->lockForUpdate()->first();
            abort_unless($node, 404, 'Node no longer exists');
            // Lock the credential row too, so token reset cannot race authorization and the write.
            $machine = Machine::query()->whereKey((int) $node->machine_id)->lockForUpdate()->first();
            abort_unless($machine, 401, 'Machine no longer exists');
            MachineNodeAuthService::authorize($request, $node, '');
            abort_unless($this->eligible($node), 409, 'Node is not eligible for certificate sync');
            abort_unless(hash_equals($this->revision($node), $configToken), 409, 'Certificate configuration changed; fetch config again');

            $pins = $this->validateCertificate($certificate, $node->tls_settings ?? []);
            $settings = $node->tls_settings ?? [];
            if (($settings['certificate_fingerprint'] ?? null) === $pins['certificate_fingerprint']
                && ($settings['certificate_public_key_sha256'] ?? null) === $pins['certificate_public_key_sha256']) {
                return ['accepted' => true, 'changed' => false, 'config_token' => $this->revision($node)];
            }

            $previous = $settings['certificate_fingerprint'] ?? null;
            $node->tls_settings = array_merge($settings, $pins);
            $node->saveOrFail();
            $summary = [
                'node_id' => (int) $node->id, 'machine_id' => (int) $node->machine_id,
                'protocol' => (string) $node->protocol,
                'previous_fingerprint' => is_string($previous) && preg_match('/\A(?:[a-f0-9]{64}|(?:[a-f0-9]{2}:){31}[a-f0-9]{2})\z/i', $previous)
                    ? strtolower(str_replace(':', '', $previous)) : null,
                'certificate_fingerprint' => $pins['certificate_fingerprint'],
                'previous_public_key_sha256' => $settings['certificate_public_key_sha256'] ?? [],
                'certificate_public_key_sha256' => $pins['certificate_public_key_sha256'],
            ];
            DB::afterCommit(static function () use ($summary) {
                Cache::forget('servers_v2node');
                Log::info('Node certificate identity synchronized', $summary);
            });
            return ['accepted' => true, 'changed' => true, 'config_token' => $this->revision($node)];
        }, 3);
    }

    private function eligible(ServerV2node $node): bool
    {
        return (int) ($node->parent_id ?? 0) === 0
            && (int) $node->machine_id > 0
            && (int) $node->tls === 1
            && in_array($node->protocol, self::PROTOCOLS, true)
            && ($node->tls_settings['cert_mode'] ?? '') === 'self';
    }

    private function revision(ServerV2node $node): string
    {
        $identity = $node->attributesToArray();
        unset($identity['updated_at']);
        // Pins are part of the CAS state; only updated_at is excluded from this token.
        $identity = $this->canonical($identity);
        $key = (string) config('app.key');
        abort_if($key === '', 503, 'Certificate sync key unavailable');
        return 'certificate-v1.' . hash_hmac('sha256', 'node-certificate-sync:v1:' . json_encode(
            $identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ), $key);
    }

    private function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) $item = $this->canonical($item);
        }
        unset($item);
        return $value;
    }

    private function validateCertificate(string $certificate, array $settings): array
    {
        $pattern = '/\A[ \t\r\n]*-----BEGIN CERTIFICATE-----\r?\n([A-Za-z0-9+\/=\r\n]+)\r?\n-----END CERTIFICATE-----[ \t\r\n]*\z/';
        if (strlen($certificate) > 16384 || preg_match($pattern, $certificate, $match) !== 1) {
            $this->invalidCertificate('Exactly one certificate PEM, at most 16 KiB, is required');
        }
        $der = base64_decode(str_replace(["\r", "\n"], '', $match[1]), true);
        $cert = @openssl_x509_read($certificate);
        $details = $cert ? @openssl_x509_parse($cert) : false;
        if ($der === false || !$details || !@openssl_x509_export($cert, $normalized)) {
            $this->invalidCertificate('Invalid certificate');
        }
        $normalizedDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $normalized), true);
        if ($der !== $normalizedDer) {
            $this->invalidCertificate('Invalid certificate encoding');
        }
        $now = time();
        if (($details['validFrom_time_t'] ?? PHP_INT_MAX) > $now || ($details['validTo_time_t'] ?? 0) <= $now) {
            $this->invalidCertificate('Certificate is not currently valid');
        }
        $name = trim((string) ($settings['server_name'] ?? ''));
        if (!$this->matchesName($details, $name === '' ? self::DEFAULT_SERVER_NAME : $name)) {
            $this->invalidCertificate('Certificate does not match the configured server name');
        }
        return NodeTlsBootstrap::certificatePins($certificate);
    }

    private function matchesName(array $details, string $name): bool
    {
        $names = [];
        foreach (explode(',', $details['extensions']['subjectAltName'] ?? '') as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) $names[] = substr($entry, 4);
            if (str_starts_with($entry, 'IP Address:')) $names[] = substr($entry, 11);
        }
        // DNS/IP SANs take precedence; a matching CN must not bypass a mismatched SAN.
        if (!$names) $names = [(string) ($details['subject']['CN'] ?? '')];
        foreach ($names as $candidate) {
            if (filter_var($name, FILTER_VALIDATE_IP)) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) && inet_pton($candidate) === inet_pton($name)) return true;
                continue;
            }
            $candidate = strtolower(rtrim($candidate, '.'));
            $host = strtolower(rtrim($name, '.'));
            if ($candidate !== '' && $candidate === $host) return true;
            if (str_starts_with($candidate, '*.') && substr_count($candidate, '.') >= 2) {
                $dot = strpos($host, '.');
                if ($dot !== false && $dot > 0 && substr($host, $dot + 1) === substr($candidate, 2)) return true;
            }
        }
        return false;
    }

    private function invalidCertificate(string $message): void
    {
        throw ValidationException::withMessages(['certificate' => $message]);
    }
}
