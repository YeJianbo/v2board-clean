<?php

namespace App\Services\Auth;

use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\UserService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RegistrationPersistenceService
{
    public function save(User $draft, Request $request): User
    {
        if ($draft->exists || $draft->getKey()) throw new \InvalidArgumentException('Registration requires a new user');
        $emailKey = strtolower(trim((string) $draft->email));
        abort_if(User::isConnectivityTestEmail($emailKey), 422, __('Email format is incorrect'));
        $policy = [
            'email_verify' => (bool) (int) config('v2board.email_verify', 0),
            'invite_force' => (bool) (int) config('v2board.invite_force', 0),
            'invite_never_expire' => (bool) (int) config('v2board.invite_never_expire', 0),
            'limit_enable' => (bool) (int) config('v2board.register_limit_by_ip_enable', 0),
            'limit_count' => (int) config('v2board.register_limit_count', 3),
            'limit_expire' => max(1, (int) config('v2board.register_limit_expire', 60)),
            'plan_id' => (int) config('v2board.try_out_plan_id', 0),
            'try_out_hour' => config('v2board.try_out_hour', 1),
        ];
        $persist = function () use ($draft, $request, $emailKey, $policy): User {
            // Password resets use the same email-code lock; no nested account-session lock is held here.
            return Cache::lock('auth:password-reset:' . $emailKey, 120)->block(2, function () use ($draft, $request, $emailKey, $policy): User {
                $codeKey = CacheKey::get('EMAIL_VERIFY_CODE', $emailKey);
                $emailCode = null;
                if ($policy['email_verify']) {
                    $input = $request->input('email_code');
                    $emailCode = Cache::get($codeKey);
                    if (!is_string($input) || !preg_match('/^\d{6}$/', $input) || $emailCode === null || $emailCode === ''
                        || !hash_equals((string) $emailCode, $input)) abort(500, __('Incorrect email verification code'));
                }
                $limitKey = CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip());
                $oldCount = null; $reserved = false;
                if ($policy['limit_enable']) {
                    $oldCount = Cache::get($limitKey);
                    if ((int) $oldCount >= $policy['limit_count']) abort(500, __('Register frequently, please try again after :minute minute', ['minute' => $policy['limit_expire']]));
                    if ($oldCount === null && !Cache::add($limitKey, 0, $policy['limit_expire'] * 60) && !Cache::has($limitKey)) {
                        throw new \RuntimeException('Registration counter unavailable');
                    }
                    if (Cache::increment($limitKey) === false) throw new \RuntimeException('Registration counter unavailable');
                    $reserved = true;
                }
                try {
                    $attributes = $draft->getAttributes();
                    $user = $draft->getConnection()->transaction(function () use ($draft, $attributes, $request, $policy): User {
                        if ($draft->newQuery()->where('email', $draft->email)->exists()) abort(500, __('Email already exists'));
                        $plan = $policy['plan_id'] ? Plan::on($draft->getConnectionName())->whereKey($policy['plan_id'])->sharedLock()->first() : null;
                        $user = $draft->newInstance([], false);
                        $user->setRawAttributes($attributes);
                        $user->last_login_at = time();
                        if ($plan) app(UserService::class)->applyResolvedPlan($user, $plan, time() + ($policy['try_out_hour'] * 3600));
                        $invite = $request->input('invite_code');
                        if ($policy['invite_force'] && !$invite) abort(500, __('You must use the invitation code to register'));
                        if ($invite) {
                            $code = InviteCode::on($draft->getConnectionName())->where('code', $invite)->where('status', 0)->lockForUpdate()->first();
                            if (!$code && $policy['invite_force']) abort(500, __('Invalid invitation code'));
                            if ($code) {
                                $user->invite_user_id = $code->user_id ?: null;
                                if (!$policy['invite_never_expire']) {
                                    $code->status = 1;
                                    if (!$code->save()) throw new \RuntimeException('Invitation consumption rejected');
                                }
                            }
                        }
                        if (!$user->save() || !$user->exists || !$user->getKey()) throw new \RuntimeException('Registration save rejected');
                        return $user;
                    }, 3);
                } catch (\Throwable $exception) {
                    if ($reserved) {
                        try {
                            $restored = $oldCount === null ? Cache::forget($limitKey) : Cache::decrement($limitKey) !== false;
                            if (!$restored) throw new \RuntimeException('Registration counter restoration rejected');
                        } catch (\Throwable $cleanup) {
                            \Log::warning('Registration counter cleanup failed', ['exception_type' => get_class($cleanup)]);
                        }
                    }
                    throw $exception;
                }
                // Do not consume the challenge until both database records have committed.
                try {
                    if ($emailCode !== null && (string) Cache::get($codeKey) === (string) $emailCode) {
                        if (!Cache::forget($codeKey) && Cache::has($codeKey)) throw new \RuntimeException('Registration challenge consumption rejected');
                    }
                } catch (\Throwable $exception) {
                    \Log::warning('Registration challenge cleanup failed', ['exception_type' => get_class($exception)]);
                    abort(500, '账户已创建，请直接登录；若登录失败，请稍后重试。');
                }
                return $user;
            });
        };
        return $policy['limit_enable'] ? Cache::lock('auth:register-ip:' . $request->ip(), 120)->block(2, $persist) : $persist();
    }
}
