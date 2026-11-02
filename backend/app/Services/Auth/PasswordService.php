<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PasswordService
{
    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 8 || mb_strlen($password) > 1024) abort(500, __('Password must be greater than 8 digits'));
    }

    private function save(User $user, string $hash, callable $revoke): void
    {
        $user->password = $hash; $user->password_algo = null; $user->password_salt = null;
        if (!$user->save()) throw new \RuntimeException('Password save rejected');
        if (!$revoke() || Cache::has(CacheKey::get('USER_SESSIONS', $user->id))) throw new \RuntimeException('Session revocation rejected');
    }

    public function change(int $id, string $oldPassword, string $newPassword): void
    {
        $this->validatePassword($newPassword);
        if (mb_strlen($oldPassword) > 1024) abort(500, __('The old password is wrong'));
        $snapshot = User::find($id);
        if (!$snapshot) abort(500, __('The user does not exist'));
        if (!Helper::multiPasswordVerify($snapshot->password_algo, $snapshot->password_salt, $oldPassword, $snapshot->password)) abort(500, __('The old password is wrong'));
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        AuthService::revokeUsers([$id], function ($revoke) use ($snapshot, $hash): bool {
            return DB::transaction(function () use ($snapshot, $hash, $revoke): bool {
                $user = User::whereKey($snapshot->id)->lockForUpdate()->first();
                if (!$user) abort(500, __('The user does not exist'));
                foreach (['password', 'password_algo', 'password_salt'] as $field) {
                    if (!hash_equals((string) ($snapshot->{$field} ?? ''), (string) ($user->{$field} ?? ''))) abort(500, __('The old password is wrong'));
                }
                $this->save($user, $hash, $revoke);
                return true;
            }, 3);
        });
    }

    public function reset(string $email, string $code, string $password, bool $notifyHook = false): void
    {
        $this->validatePassword($password);
        if (mb_strlen($email) > 64) abort(500, __('Email format is incorrect'));
        $normalized = strtolower(trim($email));
        Cache::lock('auth:password-reset:' . $normalized, 120)->block(2, function () use ($email, $normalized, $code, $password, $notifyHook): void {
            $limitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $normalized);
            $attempts = (int) Cache::get($limitKey, 0);
            if ($attempts >= 3) abort(500, __('Reset failed, Please try again later'));
            $codeKey = CacheKey::get('EMAIL_VERIFY_CODE', $normalized);
            $cached = Cache::get($codeKey);
            if (!preg_match('/^\d{6}$/', $code) || $cached === null || $cached === '' || !hash_equals((string) $cached, $code)) {
                Cache::put($limitKey, $attempts + 1, 300);
                abort(500, __('Incorrect email verification code'));
            }
            $id = User::byEmail($email)->value('id');
            if (!$id) abort(500, __('This email is not registered in the system'));
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $saved = null;
            AuthService::revokeUsers([(int) $id], function ($revoke) use ($id, $normalized, $hash, $codeKey, &$saved): bool {
                $saved = DB::transaction(function () use ($id, $normalized, $hash, $codeKey, $revoke): User {
                    $user = User::whereKey($id)->lockForUpdate()->first();
                    if (!$user || strtolower(trim($user->email)) !== $normalized) abort(500, __('This email is not registered in the system'));
                    $this->save($user, $hash, $revoke);
                    if (!Cache::forget($codeKey)) throw new \RuntimeException('Verification code consumption failed');
                    return $user;
                }, 3);
                return true;
            });
            if ($notifyHook) HookManager::call('user.password.reset.after', $saved);
        });
    }
}
