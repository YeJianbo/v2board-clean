<?php

namespace App\Services;

use App\Exceptions\UserMutationException;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\StatUser;
use App\Models\StatUserServer;
use App\Models\StatUserServerHour;
use App\Models\StatUserServerMinute;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserDeletionService
{
    public function delete(Request $request, int $id, bool $legacy = false): void
    {
        $deleted = null;
        AuthService::revokeUsers([$id], function ($revoke) use ($request, $id, $legacy, &$deleted): bool {
            $deleted = DB::transaction(function () use ($request, $id, $legacy, $revoke): User {
                $user = User::whereKey($id)->lockForUpdate()->first();
                if (!$user) throw new UserMutationException('用户不存在', 400202);
                if (!$legacy) HookManager::call('admin.user.destroy.before', ['user' => $user, 'request' => $request]);
                if ((int) $user->getKey() !== $id) throw new \RuntimeException('User deletion target changed');

                $orders = Order::where('user_id', $id);
                if (!$legacy) $orders->orWhere('invite_user_id', $id);
                $orders->delete();
                if (!$legacy) CommissionLog::where('user_id', $id)->orWhere('invite_user_id', $id)->delete();
                InviteCode::where('user_id', $id)->delete();
                TicketMessage::whereIn('ticket_id', Ticket::where('user_id', $id)->select('id'))->delete();
                Ticket::where('user_id', $id)->delete();
                if (!$legacy) {
                    foreach ([StatUser::class, StatUserServer::class, StatUserServerHour::class, StatUserServerMinute::class] as $model) {
                        $model::where('user_id', $id)->delete();
                    }
                }
                User::where('invite_user_id', $id)->update(['invite_user_id' => null]);
                if (!$user->delete() || (int) $user->getKey() !== $id || User::whereKey($id)->exists()) throw new \RuntimeException('User deletion rejected');

                // Hold the login lock through commit; rejected model hooks must not log out the user.
                if (!$revoke() || Cache::has(CacheKey::get('USER_SESSIONS', $id))) throw new \RuntimeException('Session revocation rejected');
                return $user;
            }, 3);
            return true;
        });
        if (!$legacy) HookManager::call('admin.user.destroy.after', ['user' => $deleted, 'request' => $request]);
    }
}
