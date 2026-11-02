<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class AdminBrowserSessionService
{
    public const COOKIE = '__Host-panelcore_admin';

    public function usesCookie(Request $request): bool
    {
        return $request->header('X-Admin-Request') === '1';
    }

    public function assertBrowserRequest(Request $request): void
    {
        if (!$this->usesCookie($request)
            || strtolower((string) $request->header('Sec-Fetch-Site')) === 'cross-site') {
            abort(403, 'Invalid admin browser request');
        }

        // Origin takes precedence, including invalid/null values; Referer is only a fallback.
        $hasOrigin = $request->headers->has('Origin');
        $source = $request->header($hasOrigin ? 'Origin' : 'Referer');
        $origin = $this->origin($source, $hasOrigin);
        if ($origin === null || $origin !== $this->origin($request->getSchemeAndHttpHost(), true)) {
            abort(403, 'Admin browser requests must be same-origin');
        }
    }

    private function origin($url, bool $originOnly): ?string
    {
        if (!is_string($url) || $url === '' || preg_match('/[\s\\\\]/', $url)) return null;
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) return null;
        if ($originOnly && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']))) return null;
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['https', 'http'], true)) return null;
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    public function authenticateToken($jwt): array
    {
        $user = AuthService::decryptAuthData($jwt);
        if (!$user) abort(401, 'Admin session expired');
        if (empty($user['is_admin'])) abort(403, 'Admin session required');
        try {
            $claims = (array) JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
        } catch (\Throwable $exception) {
            // The JWT may expire between the Redis-backed identity check and this decode.
            abort(401, 'Admin session expired');
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] <= time()) abort(401, 'Admin session expired');
        return ['jwt' => $jwt, 'user' => $user, 'session' => (string) $claims['session'],
            'expires_at' => (int) $claims['exp']];
    }

    public function authenticateCookie(Request $request, bool $requireCsrf = true): array
    {
        $this->assertBrowserRequest($request);
        $session = $this->authenticateToken($request->cookie(self::COOKIE));
        if ($requireCsrf) {
            $csrf = $request->header('X-Admin-CSRF');
            if (!is_string($csrf) || !hash_equals($this->sessionKey($session), $csrf)) {
                abort(403, 'Invalid admin CSRF token');
            }
        }
        return $session;
    }

    public function identity(Request $request)
    {
        if ($this->usesCookie($request)) return $this->authenticateCookie($request)['user'];
        // Keep the legacy API credential precedence and JWT format unchanged.
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        return AuthService::decryptAuthData($authorization);
    }

    public function sessionKey(array $session): string
    {
        return hash_hmac('sha256', "panelcore-admin-browser-session\0" . $session['jwt'], (string) config('app.key'));
    }

    public function data(array $session): array
    {
        $key = $this->sessionKey($session);
        return ['is_admin' => true, 'csrf_token' => $key, 'session_key' => $key];
    }

    public function cookie(array $session): Cookie
    {
        return new Cookie(self::COOKIE, $session['jwt'], $session['expires_at'], '/', null, true, true, false, 'strict');
    }

    public function logout(Request $request, array $session): void
    {
        $key = $request->input('session_key');
        if (!is_string($key) || !hash_equals($this->sessionKey($session), $key)) {
            abort(403, 'Admin session changed');
        }
        $user = new User();
        $user->id = $session['user']['id'];
        if (!(new AuthService($user))->removeSession($session['session'])) {
            abort(503, 'Admin session revocation failed');
        }
    }

    public function expiredCookie(): Cookie
    {
        return new Cookie(self::COOKIE, '', 1, '/', null, true, true, false, 'strict');
    }
}
