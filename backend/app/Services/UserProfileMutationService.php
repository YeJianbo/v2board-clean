<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserProfileMutationService
{
    public function updatePreferences(int $id, array $input): void
    {
        $patch = array_intersect_key($input, array_flip(['auto_renewal', 'remind_expire', 'remind_traffic']));
        if (!$patch) return;
        $patch = array_map(static fn ($value) => (int) $value, $patch);
        $this->mutate($id, static function (User $user) use ($patch): void { $user->fill($patch); });
    }

    public function resetSubscription(int $id, string $mode = 'immediate'): User
    {
        return $this->mutate($id, static function (User $user) use ($mode): void {
            app(SubscriptionGraceService::class)->rotate($user, $mode);
        });
    }

    public function unbindTelegram(int $id): void
    {
        $this->mutate($id, static function (User $user): void { $user->telegram_id = null; });
    }

    private function mutate(int $id, callable $apply): User
    {
        $saved = null;
        // The account lock also protects security changes made by model hooks from racing login.
        AuthService::revokeUsers([$id], function ($revoke) use ($id, $apply, &$saved): bool {
            $saved = DB::transaction(function () use ($id, $apply, $revoke): User {
                $user = User::whereKey($id)->lockForUpdate()->first();
                if (!$user) abort(500, __('The user does not exist'));
                if ($user->banned) abort(500, __('Your account has been suspended'));
                $original = $user->getRawOriginal();
                $apply($user);
                if (!$user->save() || (int) $user->getKey() !== $id) throw new \RuntimeException('User profile save rejected');
                $invalidate = $user->wasChanged('password');
                foreach (['banned', 'is_admin', 'is_staff'] as $field) {
                    if ((int) $user->{$field} !== (int) ($original[$field] ?? 0)) $invalidate = true;
                }
                if ($invalidate && !$revoke()) throw new \RuntimeException('Session revocation rejected');
                return $user;
            }, 3);
            return true;
        });
        return $saved;
    }
}
