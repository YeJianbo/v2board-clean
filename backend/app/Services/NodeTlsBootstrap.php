<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class NodeTlsBootstrap
{
    public static function anytls(array $settings): array
    {
        unset($settings['cert_managed']);
        if (empty($settings['cert_mode'])) {
            // Keep the existing quick-create contract for older URI clients.
            $settings = self::generate($settings);
            $settings['cert_mode'] = 'remote';
            $settings['allow_insecure'] = 1;
            return self::withCertificatePins($settings);
        }
        return self::forNode($settings, [], true, 'anytls', 1);
    }

    public static function forNode(array $settings, array $previous, bool $creating, string $protocol, int $tls): array
    {
        if ($tls !== 1 || !in_array($protocol, ['anytls', 'hysteria2', 'trojan', 'tuic', 'vless', 'vmess'], true)) {
            return $settings;
        }

        $mode = strtolower(trim((string) ($settings['cert_mode'] ?? '')));
        if ($mode === '' && !$creating && ($previous['cert_mode'] ?? '') === 'remote') {
            $mode = 'remote';
        }
        unset($settings['cert_managed']);
        if ($mode === 'managed') {
            throw ValidationException::withMessages(['tls_settings.cert_mode' => '不支持的证书模式']);
        }
        if ($mode !== 'remote') {
            return $settings;
        }

        // Empty PEM fields in an editor are not a request to rotate the identity.
        if (($previous['cert_mode'] ?? '') === 'remote') {
            foreach (['tls_cert', 'tls_key'] as $field) {
                if (empty($settings[$field]) && !empty($previous[$field])) {
                    $settings[$field] = $previous[$field];
                }
            }
        }

        if (!$creating && ($previous['cert_mode'] ?? '') === 'remote'
            && empty($settings['tls_cert']) && empty($settings['tls_key'])
            && empty($previous['tls_cert']) && empty($previous['tls_key'])) {
            // Older runtimes may use a cached local pair in remote mode.
            return array_merge($settings, ['cert_mode' => 'remote']);
        }
        if (empty($settings['tls_cert']) || empty($settings['tls_key'])) {
            throw ValidationException::withMessages(['tls_settings' => '远程证书需要完整的证书与私钥']);
        }

        $settings = self::withCertificatePins($settings);
        $settings['cert_mode'] = 'remote';
        return $settings;
    }

    public static function withCertificatePins(array $settings): array
    {
        $cert = @openssl_x509_read($settings['tls_cert'] ?? '');
        $key = @openssl_pkey_get_private($settings['tls_key'] ?? '');
        if (!$cert || !$key || !@openssl_x509_check_private_key($cert, $key)) {
            throw ValidationException::withMessages(['tls_settings' => '证书或私钥无效，或证书与私钥不匹配']);
        }
        return array_merge($settings, self::certificatePins($settings['tls_cert']));
    }

    public static function certificatePins(string $certificate): array
    {
        $cert = @openssl_x509_read($certificate);
        $key = $cert ? @openssl_pkey_get_public($cert) : false;
        $public = $key ? openssl_pkey_get_details($key) : false;
        $der = $public ? base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $public['key']), true) : false;
        if ($der === false) {
            throw ValidationException::withMessages(['certificate' => '无法计算节点证书 PIN']);
        }
        return [
            'certificate_fingerprint' => openssl_x509_fingerprint($cert, 'sha256'),
            'certificate_public_key_sha256' => [base64_encode(hash('sha256', $der, true))],
        ];
    }

    private static function generate(array $settings): array
    {
        $name = $settings['server_name'] ?? 'genshin.hoyoverse.com';
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        abort_unless($key, 500, '无法生成节点证书密钥');
        $csr = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
        $cert = $csr ? openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX)) : false;
        abort_unless($cert, 500, '无法签发节点证书');
        abort_unless(openssl_x509_export($cert, $pem) && openssl_pkey_export($key, $private), 500, '无法导出节点证书');
        return array_merge($settings, [
            'server_name' => $name, 'tls_cert' => $pem, 'tls_key' => $private,
            'allow_insecure' => 1,
        ]);
    }
}
