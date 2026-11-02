<?php

namespace App\Services;

use App\Exceptions\UserMutationException;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserMutationService
{
    public function update(Request $request, array $input, bool $legacy = false): User
    {
        $id = (int) $input['id'];
        if (isset($input['password'])) {
            $input['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
            $input['password_algo'] = null;
        } else unset($input['password']);
        foreach (['balance', 'commission_balance'] as $field) {
            if (!isset($input[$field])) continue;
            // Round in the input unit first: 1.005 * 100 is not exactly 100.5 in binary floating point.
            $amount = round((float) $input[$field], $legacy ? 0 : 2, PHP_ROUND_HALF_UP);
            $cents = round($amount * ($legacy ? 1 : 100), 0, PHP_ROUND_HALF_UP);
            if ($cents < -2147483648 || $cents > 2147483647) throw new UserMutationException('金额超出存储上限', 422);
            $input[$field] = (int) $cents;
        }

        $saved = null;
        $savedParams = [];
        // The same session lock as login and ban protects a permission change from racing session creation.
        AuthService::revokeUsers([$id], function ($revoke) use ($request, $input, $id, $legacy, &$saved, &$savedParams): bool {
            $result = DB::transaction(function () use ($request, $input, $id, $legacy, $revoke): array {
                $params = $input;
                $plan = null;
                // Keep plan -> user lock order consistent with plan deletion.
                if (isset($params['plan_id'])) {
                    $plan = Plan::whereKey($params['plan_id'])->sharedLock()->first(['id', 'group_id']);
                    if (!$plan) throw new UserMutationException('订阅计划不存在', 400202);
                }
                $user = User::whereKey($id)->lockForUpdate()->first();
                if (!$user) throw new UserMutationException('用户不存在', 400202);
                if (isset($params['email'])) {
                    $params['email'] = trim($params['email']);
                    if (User::byEmail($params['email'])->where('id', '!=', $id)->exists()) throw new UserMutationException('邮箱已被使用', 400201);
                }
                if (array_key_exists('plan_id', $params)) $params['group_id'] = $plan ? $plan->group_id : null;
                if (array_key_exists('invite_user_email', $params)) {
                    $params['invite_user_id'] = $this->inviterId($id, $params['invite_user_email']);
                    unset($params['invite_user_email']);
                }
                if (!$legacy) $params = HookManager::filter('admin.user.update.params', $params, $request, $user);
                if (!is_array($params)) throw new \RuntimeException('Invalid user mutation filter');
                if (!$legacy) HookManager::call('admin.user.update.before', ['user' => $user, 'params' => $params, 'request' => $request]);
                $resetTraffic = (bool) ($params['reset_traffic'] ?? false);
                unset($params['reset_traffic']);
                $user->fill($params);
                if ($resetTraffic) app(TrafficResetCheckpointService::class)->reset($user);
                // Include changes made by before/saving hooks, not only the original request fields.
                $original = $user->getRawOriginal();
                if (!$user->save()) throw new \RuntimeException('User save rejected');
                if (!$resetTraffic && $user->wasChanged(['u', 'd']) && (int) $user->u === 0 && (int) $user->d === 0) {
                    app(TrafficResetCheckpointService::class)->record([$id]);
                }
                $invalidate = $user->wasChanged('password');
                foreach (['banned', 'is_admin', 'is_staff'] as $field) {
                    if ((int) $user->{$field} !== (int) ($original[$field] ?? 0)) $invalidate = true;
                }
                if ($invalidate) {
                    if (!$revoke() || Cache::has(CacheKey::get('USER_SESSIONS', $id))) throw new \RuntimeException('Session revocation rejected');
                }
                return [$user, $params];
            }, 3);
            [$saved, $savedParams] = $result;
            return true;
        });
        if (!$legacy) HookManager::call('admin.user.update.after', ['user' => $saved->refresh(), 'params' => $savedParams, 'request' => $request]);
        return $saved;
    }

    private function inviterId(int $userId, $email): ?int
    {
        $email = trim((string) ($email ?? ''));
        if ($email === '') return null;
        $id = User::byEmail($email)->value('id');
        if ($id === null) throw new UserMutationException('邀请人不存在', 400202);
        if ((int) $id === $userId) throw new UserMutationException('不能将用户自己设为邀请人', 422);
        return (int) $id;
    }

    public function resetSecret(Request $request, int $id, bool $legacy = false): User
    {
        $user = DB::transaction(static function () use ($id, $request): User {
            $user = User::whereKey($id)->lockForUpdate()->first();
            if (!$user) throw new UserMutationException('用户不存在', 400202);
            app(SubscriptionGraceService::class)->rotate($user, (string) $request->input('mode', 'immediate'));
            if (!$user->save()) throw new \RuntimeException('User secret save rejected');
            return $user;
        }, 3);
        if (!$legacy) HookManager::call('admin.user.secret.reset', ['user' => $user, 'request' => $request]);
        return $user;
    }

    public function setInviter(int $id, $email): void
    {
        DB::transaction(function () use ($id, $email): void {
            $user = User::whereKey($id)->lockForUpdate()->first();
            if (!$user) throw new UserMutationException('用户不存在', 400202);
            $user->invite_user_id = $this->inviterId($id, $email);
            if (!$user->save()) throw new \RuntimeException('User inviter save rejected');
        }, 3);
    }
}
