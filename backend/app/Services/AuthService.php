<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;

class AuthService
{
    private const DEFAULT_SESSION_TTL = 2592000;
    private const AUTH_CACHE_TTL = 300;
    private const QUICK_LOGIN_CODE_TTL = 60;
    private const MAIL_LOGIN_CODE_TTL = 300;
    private const REVOCATION_TTL = 360;

    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        return self::withSessionLocks([(int) $this->user->id], function () use ($request) {
            return DB::transaction(function () use ($request) {
                $current = self::currentUser($this->user);
                return self::createSession($current, $request);
            }, 3);
        });
    }

    private static function currentUser(User $snapshot): User
    {
        $current = User::whereKey($snapshot->id)->sharedLock()->first();
        if (!$current || (int) $current->banned === 1) abort(500, __('Your account has been suspended'));
        foreach (['email', 'password', 'password_algo', 'password_salt', 'banned', 'is_admin', 'is_staff'] as $field) {
            if (!array_key_exists($field, $snapshot->getAttributes())) continue;
            $old = self::credentialValue($snapshot, $field); $new = self::credentialValue($current, $field);
            if (!hash_equals($old, $new)) abort(500, __('Incorrect email or password'));
        }
        return $current;
    }

    private static function credentialValue(User $user, string $field): string
    {
        if (in_array($field, ['banned', 'is_admin', 'is_staff'], true)) return (string) (int) $user->{$field};
        if ($field === 'email') return strtolower(trim((string) $user->email));
        return (string) ($user->{$field} ?? '');
    }

    private static function credentialStamp(User $user): string
    {
        $values = [];
        foreach (['email', 'password', 'password_algo', 'password_salt', 'banned', 'is_admin', 'is_staff'] as $field) $values[] = self::credentialValue($user, $field);
        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    // Called only while holding the account's session lock and a shared database row lock.
    private static function createSession(User $user, Request $request): array
    {
        $guid = Helper::guid();
        $issuedAt = time();
        $expiresAt = $issuedAt + self::sessionTtl();
        $authData = JWT::encode([
            'id' => $user->id,
            'session' => $guid,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], config('app.key'), 'HS256');
        $cacheKey = CacheKey::get('USER_SESSIONS', $user->id);
        $sessions = self::cleanSessions((array) Cache::get($cacheKey, []));
        $sessions[$guid] = [
            'ip' => $request->ip(),
            'login_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'ua' => $request->userAgent(),
            'auth_data' => $authData
        ];
        if (!self::storeSessions($cacheKey, $sessions)) throw new \RuntimeException('Session persistence failed');
        return [
            'token' => $user->token,
            'is_admin' => $user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            if (!is_string($jwt) || $jwt === '' || strlen($jwt) > 8192) {
                return false;
            }

            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (empty($data['id']) || empty($data['session'])) {
                return false;
            }
            if (!self::checkSession((int) $data['id'], (string) $data['session'])) {
                Cache::forget($jwt);
                return false;
            }

            $user = Cache::get($jwt);
            if ($user === null) {
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff',
                    'banned',
                ])
                    ->find($data['id']);
                if (!$user || (int) $user->banned === 1) {
                    Cache::forget($jwt);
                    return false;
                }
                $user = $user->toArray();
                Cache::put($jwt, $user, self::AUTH_CACHE_TTL);
            }

            if (!is_array($user) || (int) ($user['id'] ?? 0) !== (int) $data['id']
                || (int) ($user['banned'] ?? 0) === 1) {
                Cache::forget($jwt);
                return false;
            }
            unset($user['banned']);
            return $user;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        if (!isset($sessions[$session]) || !is_array($sessions[$session])) {
            return false;
        }
        if ((int)($sessions[$session]['expires_at'] ?? 0) > 0) {
            return (int)$sessions[$session]['expires_at'] > time();
        }
        // Re-read legacy metadata under the same lock as revocation, never write a stale map back.
        return self::withSessionLocks([(int)$userId], function () use ($cacheKey, $session) {
            $sessions = self::cleanSessions((array)Cache::get($cacheKey, []));
            self::storeSessions($cacheKey, $sessions);
            return isset($sessions[$session]);
        });
    }

    private static function revokedKey(int $id): string { return 'auth:user-revoked:' . $id; }

    private static function markRevoked(int $id): void
    {
        // Keep revocation observable for longer than every outstanding login code.
        if (!Cache::put(self::revokedKey($id), Helper::guid(), self::REVOCATION_TTL)) throw new \RuntimeException('Revocation marker persistence failed');
    }

    public static function quickLoginCodeForUser(User $snapshot): string
    {
        return self::loginCodeForUser($snapshot, self::QUICK_LOGIN_CODE_TTL);
    }

    public static function mailLoginCodeForUser(User $snapshot): string
    {
        return self::loginCodeForUser($snapshot, self::MAIL_LOGIN_CODE_TTL);
    }

    private static function loginCodeForUser(User $snapshot, int $ttl): string
    {
        return self::withSessionLocks([(int) $snapshot->id], function () use ($snapshot, $ttl) {
            return DB::transaction(fn () => self::storeQuickCode(self::currentUser($snapshot), null, $ttl), 3);
        });
    }

    public static function quickLoginCodeForAuth(string $authorization): string
    {
        $identity = self::decryptAuthData($authorization);
        if (!$identity) abort(403, '未登录或登陆已过期');
        $jwt = (array) JWT::decode($authorization, new Key(config('app.key'), 'HS256'));
        return self::withSessionLocks([(int) $identity['id']], function () use ($identity, $jwt) {
            if (!self::sessionPresent((int) $identity['id'], (string) $jwt['session'])) abort(403, '未登录或登陆已过期');
            return DB::transaction(function () use ($identity, $jwt) {
                $snapshot = new User($identity); $snapshot->id = $identity['id'];
                return self::storeQuickCode(self::currentUser($snapshot), (string) $jwt['session']);
            }, 3);
        });
    }

    private static function storeQuickCode(User $user, ?string $session = null, int $ttl = self::QUICK_LOGIN_CODE_TTL): string
    {
        $code = Helper::guid();
        $revision = Cache::get(self::revokedKey((int) $user->id));
        // A code minted near marker expiry still needs its full validity window.
        if ($revision !== null && !Cache::put(self::revokedKey((int) $user->id), $revision, self::REVOCATION_TTL)) throw new \RuntimeException('Revocation marker persistence failed');
        $ticket = ['user_id' => (int) $user->id, 'stamp' => self::credentialStamp($user),
            'revision' => $revision, 'session' => $session];
        if (!Cache::put(CacheKey::get('TEMP_TOKEN', $code), $ticket, $ttl)) throw new \RuntimeException('Quick login persistence failed');
        return $code;
    }

    private static function sessionPresent(int $id, string $session): bool
    {
        $meta = ((array) Cache::get(CacheKey::get('USER_SESSIONS', $id), []))[$session] ?? null;
        if (!is_array($meta)) return false;
        return self::sessionExpiresAt($meta) > time();
    }

    public static function redeemQuickLoginCode(string $code, Request $request): array
    {
        return Cache::lock('auth:quick-login:' . $code, 10)->block(2, function () use ($code, $request) {
            $key = CacheKey::get('TEMP_TOKEN', $code); $ticket = Cache::get($key);
            $id = is_array($ticket) ? ($ticket['user_id'] ?? null) : $ticket;
            if (!is_numeric($id) || (int) $id <= 0 || !Cache::forget($key)) abort(500, __('Token error'));
            // Consume before waiting for the account lock: a stalled request cannot replay the code.
            return self::withSessionLocks([(int) $id], function () use ($id, $ticket, $request) {
                return DB::transaction(function () use ($id, $ticket, $request) {
                    $user = User::whereKey($id)->sharedLock()->first();
                    if (!$user || (int) $user->banned === 1) abort(500, __('Token error'));
                    $revision = Cache::get(self::revokedKey((int) $id));
                    if (is_array($ticket)) {
                        if (!is_string($ticket['stamp'] ?? null) || !hash_equals(self::credentialStamp($user), $ticket['stamp'])
                            || ($ticket['revision'] ?? null) !== $revision
                            || (!empty($ticket['session']) && !self::sessionPresent((int) $id, (string) $ticket['session']))) abort(500, __('Token error'));
                    } elseif ($revision !== null) abort(500, __('Token error'));
                    return self::createSession($user, $request);
                }, 3);
            });
        });
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function getSessionMetadata(): array
    {
        $result = [];
        $now = time();
        foreach ($this->getSessions() as $id => $meta) {
            if (!is_array($meta)) continue;
            $expiresAt = self::sessionExpiresAt($meta);
            if ($expiresAt <= $now) continue;
            $result[$id] = array_intersect_key($meta, array_flip(['ip', 'login_at', 'ua']));
            $result[$id]['expires_at'] = $expiresAt;
        }
        return $result;
    }

    public function removeSession($sessionId)
    {
        return self::withSessionLocks([(int)$this->user->id], function () use ($sessionId) {
            $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
            $sessions = (array)Cache::get($cacheKey, []);
            if (is_string($sessions[$sessionId]['auth_data'] ?? null)) Cache::forget($sessions[$sessionId]['auth_data']);
            unset($sessions[$sessionId]);
            return self::storeSessions($cacheKey, self::cleanSessions($sessions));
        });
    }

    public function removeAllSession()
    {
        return self::revokeUsers([(int) $this->user->id]);
    }

    public static function revokeUsers(array $userIds, ?callable $mutation = null): bool
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        return self::withSessionLocks($userIds, function () use ($userIds, $mutation) {
            $revoke = function () use ($userIds): bool {
                // Unreachable JWT identity entries expire after five minutes; no per-session reads needed.
                foreach ($userIds as $id) {
                    self::markRevoked($id);
                    $key = CacheKey::get('USER_SESSIONS', $id);
                    // Redis DEL also returns false for an absent key; distinguish that from a failed deletion.
                    if (!Cache::forget($key) && Cache::has($key)) throw new \RuntimeException('Session revocation rejected');
                }
                return true;
            };
            return $mutation ? (bool)$mutation($revoke) : $revoke();
        });
    }

    private static function withSessionLocks(array $userIds, callable $callback)
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids, SORT_NUMERIC);
        if (!$ids || count($ids) > 200 || $ids[0] <= 0) throw new \InvalidArgumentException('Invalid session mutation batch');
        if (count($ids) === 1) return Cache::lock('auth:user-sessions:' . $ids[0], 120)->block(2, $callback);
        $locks = [];
        try {
            foreach ($ids as $id) {
                $lock = Cache::lock('auth:user-sessions:' . $id, 120);
                if (!$lock->get()) throw new LockTimeoutException();
                $locks[] = $lock;
            }
            return $callback();
        } finally {
            foreach (array_reverse($locks) as $lock) $lock->release();
        }
    }

    private static function cleanSessions(array $sessions): array
    {
        $now = time();
        foreach ($sessions as $id => $meta) {
            if (!is_array($meta)) { unset($sessions[$id]); continue; }
            $expiresAt = self::sessionExpiresAt($meta);
            if ($expiresAt <= $now) {
                if (is_string($meta['auth_data'] ?? null)) Cache::forget($meta['auth_data']);
                unset($sessions[$id]);
            } else {
                $sessions[$id]['expires_at'] = $expiresAt;
            }
        }
        return $sessions;
    }

    public function removeAllSessions()
    {
        return $this->removeAllSession();
    }

    private static function sessionExpiresAt(array $meta): int
    {
        $expiresAt = (int) ($meta['expires_at'] ?? 0);
        if ($expiresAt > 0) return $expiresAt;
        $loginAt = (int) ($meta['login_at'] ?? 0);
        // Legacy records need their original issue time, never a new lifetime from now.
        return $loginAt > 0 ? $loginAt + self::sessionTtl() : 0;
    }

    private static function sessionTtl(): int
    {
        return max(3600, (int)config('v2board.auth_session_ttl', self::DEFAULT_SESSION_TTL));
    }

    private static function storeSessions(string $cacheKey, array $sessions): bool
    {
        if (!$sessions) {
            if (!Cache::forget($cacheKey) && Cache::has($cacheKey)) throw new \RuntimeException('Session deletion rejected');
            return true;
        }

        $now = time();
        $maxExpiresAt = $now;
        foreach ($sessions as $meta) {
            if (is_array($meta) && !empty($meta['expires_at'])) {
                $maxExpiresAt = max($maxExpiresAt, (int)$meta['expires_at']);
            }
        }

        return Cache::put($cacheKey, $sessions, max(1, $maxExpiresAt - $now));
    }
}
