<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

class PanelGoogleDriveService
{
    public const REMOTE_NAME = 'panelcore_panel_drive';

    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/drive.file';
    private const STATE_CACHE_PREFIX = 'panel_google_drive_oauth:';

    public function status(Request $request): array
    {
        $clientId = trim((string) admin_setting('backup_google_client_id', ''));
        $clientSecret = $this->clientSecret();
        $lastTest = $this->readJsonFile($this->statusPath());

        return [
            'configured' => $clientId !== '' && $clientSecret !== '',
            'authorized' => $this->isAuthorized(),
            'rclone_available' => $this->rcloneBinary() !== null,
            'client_id' => $clientId,
            'client_secret_configured' => $clientSecret !== '',
            'folder' => $this->folder(),
            'callback_url' => $this->callbackUrl($request),
            'authorized_at' => (string) admin_setting('backup_google_authorized_at', ''),
            'last_test' => $lastTest,
        ];
    }

    public function configure(string $clientId, string $clientSecret, string $folder): void
    {
        $this->withAuthorizationLock(fn () => $this->configureLocked($clientId, $clientSecret, $folder));
    }

    private function configureLocked(string $clientId, string $clientSecret, string $folder): void
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $folder = $this->normalizeFolder($folder);

        if ($clientId === '' || preg_match('/[\r\n]/', $clientId)) {
            throw new RuntimeException('Google OAuth Client ID 不能为空');
        }

        $currentClientId = trim((string) admin_setting('backup_google_client_id', ''));
        $currentClientSecret = $this->clientSecret();
        if ($clientSecret === '') {
            if ($currentClientSecret === '' || ($currentClientId !== '' && $currentClientId !== $clientId)) {
                throw new RuntimeException('更换 Client ID 时必须同时填写 Client Secret');
            }
            $clientSecret = $currentClientSecret;
        }

        if (preg_match('/[\r\n]/', $clientSecret)) {
            throw new RuntimeException('Google OAuth Client Secret 格式不正确');
        }

        $credentialsChanged = $currentClientId !== $clientId || $currentClientSecret !== $clientSecret;
        $settings = [
            'backup_google_client_id' => $clientId,
            'backup_google_client_secret' => Crypt::encryptString($clientSecret),
            'backup_google_folder' => $folder,
        ];
        if ($credentialsChanged) {
            $settings['backup_google_authorized_at'] = '';
        }
        if ($credentialsChanged || !admin_setting('backup_google_oauth_generation', '')) {
            $settings['backup_google_oauth_generation'] = Str::random(32);
        }

        admin_setting($settings);
        foreach ($settings as $key => $value) {
            config(['v2board.' . $key => $value]);
        }

        if ($credentialsChanged) {
            File::delete($this->configPath());
        }
    }

    public function createAuthorizationUrl(Request $request): array
    {
        return $this->withAuthorizationLock(fn () => $this->createAuthorizationUrlLocked($request));
    }

    private function createAuthorizationUrlLocked(Request $request): array
    {
        $clientId = trim((string) admin_setting('backup_google_client_id', ''));
        if ($clientId === '' || $this->clientSecret() === '') {
            throw new RuntimeException('请先保存 Google OAuth Client ID 和 Client Secret');
        }
        if ($this->rcloneBinary() === null) {
            throw new RuntimeException('服务器尚未安装 rclone，无法启用 Google Drive 备份');
        }

        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirectUri = $this->callbackUrl($request);

        Cache::put(self::STATE_CACHE_PREFIX . $state, [
            'redirect_uri' => $redirectUri,
            'return_url' => $this->defaultReturnUrl($request),
            'code_verifier' => $verifier,
            'generation' => (string) admin_setting('backup_google_oauth_generation', ''),
            'credentials' => $this->credentialFingerprint(),
        ], now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::OAUTH_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $query,
            'callback_url' => $redirectUri,
        ];
    }

    public function completeAuthorization(Request $request): array
    {
        $state = $request->query('state', '');
        if (!is_string($state) || !preg_match('/^[A-Za-z0-9]{64}$/D', $state)
            || !is_array(Cache::get(self::STATE_CACHE_PREFIX . $state))) {
            return $this->authorizationResult(false, $this->defaultReturnUrl($request), '授权请求已过期，请回到面板重新授权');
        }

        return $this->withAuthorizationLock(fn () => $this->completeAuthorizationLocked($request));
    }

    private function completeAuthorizationLocked(Request $request): array
    {
        $defaultReturnUrl = $this->defaultReturnUrl($request);
        $state = trim((string) $request->query('state', ''));
        $context = $state === '' ? null : Cache::pull(self::STATE_CACHE_PREFIX . $state);
        $returnUrl = is_array($context) ? (string) ($context['return_url'] ?? $defaultReturnUrl) : $defaultReturnUrl;

        if (!is_array($context)) {
            return $this->authorizationResult(false, $returnUrl, '授权请求已过期，请回到面板重新授权');
        }
        if (!is_string($context['generation'] ?? null) || !is_string($context['credentials'] ?? null)
            || !hash_equals((string) admin_setting('backup_google_oauth_generation', ''), $context['generation'])
            || !hash_equals($this->credentialFingerprint(), $context['credentials'])) {
            return $this->authorizationResult(false, $returnUrl, '授权配置已变更，请回到面板重新授权');
        }
        if ($request->filled('error')) {
            return $this->authorizationResult(false, $returnUrl, 'Google 授权已取消');
        }

        $code = $request->query('code', '');
        if (!is_string($code) || strlen($code) > 8192 || trim($code) === '') {
            return $this->authorizationResult(false, $returnUrl, 'Google 未返回授权码');
        }
        $code = trim($code);

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->retry(2, 300)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => trim((string) admin_setting('backup_google_client_id', '')),
                    'client_secret' => $this->clientSecret(),
                    'code' => $code,
                    'code_verifier' => (string) ($context['code_verifier'] ?? ''),
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => (string) ($context['redirect_uri'] ?? $this->callbackUrl($request)),
                ]);

            if (!$response->successful()) {
                throw new RuntimeException('Google OAuth token exchange returned HTTP ' . $response->status());
            }

            $payload = $response->json();
            $accessToken = trim((string) ($payload['access_token'] ?? ''));
            $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
            if ($accessToken === '' || $refreshToken === '') {
                throw new RuntimeException('Google OAuth response did not contain a refresh token');
            }

            $expiresIn = max(60, (int) ($payload['expires_in'] ?? 3600));
            $token = [
                'access_token' => $accessToken,
                'token_type' => (string) ($payload['token_type'] ?? 'Bearer'),
                'refresh_token' => $refreshToken,
                'expiry' => gmdate('Y-m-d\TH:i:s\Z', time() + $expiresIn),
            ];
            $this->writeRcloneConfig($token);

            $authorizedAt = now()->toIso8601String();
            admin_setting(['backup_google_authorized_at' => $authorizedAt]);
            config(['v2board.backup_google_authorized_at' => $authorizedAt]);

            return $this->authorizationResult(true, $returnUrl, 'Google Drive 授权成功');
        } catch (Throwable $error) {
            Log::warning('Panel Google Drive OAuth failed', [
                'exception' => get_class($error),
            ]);

            return $this->authorizationResult(false, $returnUrl, 'Google Drive 授权失败，请检查 OAuth 配置后重试');
        }
    }

    public function testConnection(): array
    {
        if (!$this->isAuthorized()) {
            throw new RuntimeException('Google Drive 尚未授权');
        }

        $directory = dirname($this->statusPath());
        $this->ensurePrivateDirectory($directory);
        $testName = '.panelcore-write-test-' . Str::lower(Str::random(10)) . '.txt';
        $localFile = $directory . DIRECTORY_SEPARATOR . $testName;
        $remoteFile = $this->remoteDestination() . '/' . $testName;
        File::put($localFile, 'PanelCore Google Drive backup connection test ' . now()->toIso8601String());
        @chmod($localFile, 0600);

        try {
            $this->runRclone([
                'copyto',
                $localFile,
                $remoteFile,
                '--config',
                $this->configPath(),
                '--checkers',
                '1',
                '--transfers',
                '1',
                '--retries',
                '2',
            ]);
            $this->runRclone([
                'deletefile',
                $remoteFile,
                '--config',
                $this->configPath(),
            ]);

            $status = [
                'status' => 'success',
                'tested_at' => now()->toIso8601String(),
                'folder' => $this->folder(),
                'message' => 'Google Drive 读写测试成功',
            ];
            $this->writeStatus($status);

            return $status;
        } catch (Throwable $error) {
            $status = [
                'status' => 'failed',
                'tested_at' => now()->toIso8601String(),
                'folder' => $this->folder(),
                'message' => 'Google Drive 读写测试失败，请检查授权和备份目录后重试',
            ];
            $this->writeStatus($status);
            Log::warning('Panel Google Drive connection test failed', ['exception' => get_class($error)]);
            throw new RuntimeException($status['message']);
        } finally {
            File::delete($localFile);
        }
    }

    public function disconnect(): void
    {
        $this->withAuthorizationLock(fn () => $this->disconnectLocked());
    }

    private function disconnectLocked(): void
    {
        $token = $this->readTokenFromConfig();
        admin_setting(['backup_google_authorized_at' => '', 'backup_google_oauth_generation' => Str::random(32)]);
        $revokeToken = trim((string) ($token['refresh_token'] ?? $token['access_token'] ?? ''));
        if ($revokeToken !== '') {
            try {
                Http::asForm()
                    ->timeout(10)
                    ->post('https://oauth2.googleapis.com/revoke', ['token' => $revokeToken]);
            } catch (Throwable $error) {
                Log::notice('Panel Google Drive token revocation failed', [
                    'exception' => get_class($error),
                ]);
            }
        }

        File::delete([$this->configPath(), $this->statusPath()]);
        if (is_file($this->configPath())) throw new RuntimeException('无法清除 Google Drive 本地授权，请检查目录权限');
    }

    public function isAuthorized(): bool
    {
        if (!is_file($this->configPath())) {
            return false;
        }

        $content = $this->readBoundedFile($this->configPath(), 65536);
        return strpos($content, '[' . self::REMOTE_NAME . ']') !== false
            && preg_match('/^token\s*=\s*\{.+\}$/m', $content) === 1;
    }

    public function configPath(): string
    {
        return storage_path('app/backup/rclone-panel.conf');
    }

    public function remoteDestination(): string
    {
        return self::REMOTE_NAME . ':' . $this->folder();
    }

    public function folder(): string
    {
        return $this->normalizeFolder((string) admin_setting('backup_google_folder', 'PanelCore Backups'));
    }

    private function callbackUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/') . '/api/v2/admin/backup/google/callback';
    }

    private function defaultReturnUrl(Request $request): string
    {
        $securePath = trim((string) config(
            'v2board.secure_path',
            config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))
        ), '/');

        return rtrim($request->getSchemeAndHttpHost(), '/') . '/' . $securePath . '/settings/backup';
    }

    private function authorizationResult(bool $success, string $returnUrl, string $message): array
    {
        return [
            'success' => $success,
            'return_url' => $returnUrl,
            'message' => $message,
        ];
    }

    private function clientSecret(): string
    {
        $encrypted = (string) admin_setting('backup_google_client_secret', '');
        if ($encrypted === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($encrypted));
        } catch (Throwable $error) {
            Log::warning('Panel Google Drive client secret could not be decrypted');
            return '';
        }
    }

    private function normalizeFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), " /\t\n\r\0\x0B");
        if ($folder === '' || preg_match('/[\r\n]/', $folder)) {
            return 'PanelCore Backups';
        }

        return $folder;
    }

    private function writeRcloneConfig(array $token): void
    {
        $directory = dirname($this->configPath());
        $this->ensurePrivateDirectory($directory);
        $tokenJson = json_encode($token, JSON_UNESCAPED_SLASHES);
        if ($tokenJson === false) {
            throw new RuntimeException('Unable to encode the Google Drive OAuth token');
        }

        $content = '[' . self::REMOTE_NAME . "]\n"
            . "type = drive\n"
            . 'client_id = ' . trim((string) admin_setting('backup_google_client_id', '')) . "\n"
            . 'client_secret = ' . $this->clientSecret() . "\n"
            . "scope = drive.file\n"
            . 'token = ' . $tokenJson . "\n"
            . "team_drive =\n";
        $temporaryPath = $this->configPath() . '.tmp.' . Str::lower(Str::random(8));
        File::put($temporaryPath, $content);
        @chmod($temporaryPath, 0600);
        if (!@rename($temporaryPath, $this->configPath())) {
            File::delete($temporaryPath);
            throw new RuntimeException('Unable to save the dedicated rclone configuration');
        }
        @chmod($this->configPath(), 0600);
    }

    private function readTokenFromConfig(): array
    {
        if (!is_file($this->configPath())) {
            return [];
        }

        $content = $this->readBoundedFile($this->configPath(), 65536);
        if (!preg_match('/^token\s*=\s*(\{.+\})$/m', $content, $matches)) {
            return [];
        }

        $token = json_decode($matches[1], true);
        return is_array($token) ? $token : [];
    }

    private function runRclone(array $arguments): void
    {
        $binary = $this->rcloneBinary();
        if ($binary === null) {
            throw new RuntimeException('服务器尚未安装 rclone');
        }

        $process = new Process(array_merge([$binary], $arguments), base_path());
        $process->setTimeout(120);
        $process->disableOutput();
        try {
            $process->run();
        } catch (ProcessTimedOutException $error) {
            throw new RuntimeException('Google Drive 连接测试超时，请稍后重试');
        }
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Google Drive 读写测试失败，rclone 退出码 ' . $process->getExitCode());
        }
    }

    protected function rcloneBinary(): ?string
    {
        foreach (['/usr/bin/rclone', '/usr/local/bin/rclone'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function statusPath(): string
    {
        return storage_path('app/backup/google-drive-status.json');
    }

    private function writeStatus(array $status): void
    {
        $this->ensurePrivateDirectory(dirname($this->statusPath()));
        File::put($this->statusPath(), json_encode(
            $status,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        @chmod($this->statusPath(), 0600);
    }

    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode($this->readBoundedFile($path, 16384), true);
        if (!is_array($decoded) || !in_array($decoded['status'] ?? null, ['success', 'failed'], true)) return [];
        return [
            'status' => $decoded['status'],
            'tested_at' => is_string($decoded['tested_at'] ?? null) ? substr($decoded['tested_at'], 0, 64) : '',
            'folder' => $this->folder(),
            // Historical status files may contain unredacted remote-process errors.
            'message' => $decoded['status'] === 'success' ? 'Google Drive 读写测试成功' : 'Google Drive 读写测试失败，请检查授权和备份目录后重试',
        ];
    }

    private function readBoundedFile(string $path, int $limit): string
    {
        $content = @file_get_contents($path, false, null, 0, $limit + 1);
        return is_string($content) && strlen($content) <= $limit ? $content : '';
    }

    private function credentialFingerprint(): string
    {
        return hash_hmac('sha256', (string) admin_setting('backup_google_client_id', '') . "\0" . $this->clientSecret(), (string) config('app.key'));
    }

    private function withAuthorizationLock(callable $operation)
    {
        $directory = dirname($this->configPath());
        $this->ensurePrivateDirectory($directory);
        $lock = fopen($directory . '/.google-authorization.lock', 'c');
        if ($lock === false) throw new RuntimeException('无法锁定 Google Drive 授权配置');
        chmod($directory . '/.google-authorization.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) abort(409, 'Google Drive 授权配置正在更新，请稍后重试');
            // Requests may have booted before another request changed the settings file.
            app(AdminSettingsWriter::class)->update(static fn (array $settings) => [[], null]);
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0700, true);
        }
        @chmod($directory, 0700);
    }
}
