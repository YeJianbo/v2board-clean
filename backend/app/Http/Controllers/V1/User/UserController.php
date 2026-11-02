<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Giftcard;
use App\Models\GiftcardUsage;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserOnlineStateService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id'], ['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessionMetadata()
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $params = $request->validate(['session_id' => 'required|string|max:64']);
        $user = User::find($request->user['id'], ['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($params['session_id'])
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        return response([
            'data' => $data
        ]);
    }

    public function changePassword(UserChangePassword $request)
    {
        try {
            app(\App\Services\Auth\PasswordService::class)->change((int) $request->user['id'], $request->input('old_password'), $request->input('new_password'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { throw $exception; }
        catch (\Throwable $exception) {
            \Log::warning('Password change failed', ['exception_type' => get_class($exception), 'exception' => $exception]);
            abort(500, __('Save failed'));
        }
        return response(['data' => true]);
    }

    public function newPeriod(Request $request) 
    {
        if (!config('v2board.allow_new_period', 0)) {
            abort(500, __('Renewal is not allowed'));
        }
        try {
            app(\App\Services\AdvancePeriodService::class)->advance((int) $request->user['id']);
            return response([
                'data' => true
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('Period advance failed', ['exception_type' => get_class($exception), 'exception' => $exception]);
            abort(500, __('Save failed'));
        }
    }

    public function redeemgiftcard(UserRedeemGiftCard $request)
    {
        $result = DB::transaction(function () use ($request) {
            $giftcard = Giftcard::where('code', $request->giftcard)->lockForUpdate()->first();
            if (!$giftcard) {
                abort(422, __('The gift card does not exist'));
            }

            $user = User::whereKey($request->user['id'])->lockForUpdate()->first();
            if (!$user) {
                abort(422, __('The user does not exist'));
            }
            if (!(bool)$giftcard->enabled) {
                abort(422, __('The gift card is disabled'));
            }

            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(422, __('The gift card is not yet valid'));
            }
            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(422, __('The gift card has expired'));
            }
            if ($giftcard->limit_use !== null && (int)$giftcard->limit_use <= 0) {
                abort(422, __('The gift card usage limit has been reached'));
            }

            $usedUserIds = (array)($giftcard->used_user_ids ?: []);
            if (in_array((int)$user->id, array_map('intval', $usedUserIds), true)
                || GiftcardUsage::where('giftcard_id', $giftcard->id)->where('user_id', $user->id)->exists()) {
                abort(422, __('The gift card has already been used by this user'));
            }

            switch ((int)$giftcard->type) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(422, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    app(\App\Services\TrafficResetCheckpointService::class)->reset($user);
                    break;
                case 5:
                    if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                        $plan = Plan::find($giftcard->plan_id);
                        if (!$plan) {
                            abort(422, __('Subscription plan does not exist'));
                        }
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->speed_limit = $plan->speed_limit;
                        app(\App\Services\TrafficResetCheckpointService::class)->reset($user);
                        if($giftcard->value == 0) {
                            $user->expired_at = null;
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    } else {
                        abort(422, __('Not suitable gift card type'));
                    }
                    break;
                default:
                    abort(422, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use = (int)$giftcard->limit_use - 1;
            }
            $usedUserIds[] = (int)$user->id;
            $giftcard->used_user_ids = array_values(array_unique(array_map('intval', $usedUserIds)));
            $giftcard->used_at = $currentTime;
            $giftcard->used_by_user_id = $user->id;

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }

            $usage = GiftcardUsage::create([
                'giftcard_id' => $giftcard->id,
                'user_id' => $user->id,
                'type' => $giftcard->type,
                'value' => $giftcard->value,
                'plan_id' => $giftcard->plan_id,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string)$request->userAgent(), 0, 500),
                'created_at' => $currentTime,
                'updated_at' => $currentTime,
            ]);
            if (!$usage->exists) {
                throw new \RuntimeException('Gift card usage record was rejected.');
            }

            return ['type' => (int)$giftcard->type, 'value' => (int)$giftcard->value];
        }, 3);

        return response(['data' => true] + $result);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'auto_renewal',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user['avatar_url'] = 'https://cravatar.cn/avatar/' . md5($user->email) . '?s=64&d=identicon';
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $userId = $request->user['id'];
        $counts = DB::query()
            ->selectSub(Order::where('status', 0)->where('user_id', $userId)->selectRaw('COUNT(*)'), 'orders')
            ->selectSub(Ticket::where('status', 0)->where('user_id', $userId)->selectRaw('COUNT(*)'), 'tickets')
            ->selectSub(User::where('invite_user_id', $userId)->selectRaw('COUNT(*)'), 'invites')
            ->first();
        $stat = [(int) $counts->orders, (int) $counts->tickets, (int) $counts->invites];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }

        $user['alive_ip'] = UserOnlineStateService::count(Cache::get('ALIVE_IP_USER_' . $request->user['id']));

        $subscribeMethod = (int) config('v2board.show_subscribe_method', 0);
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token'], (int) $request->user['id']);
        $user['subscribe_url_dynamic'] = in_array($subscribeMethod, [2, 3], true);
        $user['subscribe_url_expire_seconds'] = Helper::getSubscribeExpireSeconds();
        $user['grace_expires_at'] = app(\App\Services\SubscriptionGraceService::class)->expiresAt((int) $request->user['id']);

        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user['allow_new_period'] = config('v2board.allow_new_period', 0);
        return response([
            'data' => $user
        ])->header('Cache-Control', 'private, no-store');
    }

    public function unbindTelegram(Request $request)
    {
        if ($request->isMethod('head')) abort(405);
        try {
            app(\App\Services\UserProfileMutationService::class)->unbindTelegram((int) $request->user['id']);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('Telegram unbind failed', ['exception_type' => get_class($exception), 'exception' => $exception]);
            abort(500, __('Unbind telegram failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function resetSecurity(Request $request)
    {
        if ($request->isMethod('head')) abort(405);
        $input = $request->validate(['mode' => 'sometimes|required|in:grace,immediate,revoke']);
        $rotated = false;
        try {
            $user = app(\App\Services\UserProfileMutationService::class)->resetSubscription((int) $request->user['id'], $input['mode'] ?? 'immediate');
            $rotated = true;
            return response(['data' => Helper::getSubscribeUrl($user->token, (int) $user->id),
                'grace_expires_at' => app(\App\Services\SubscriptionGraceService::class)->expiresAt((int) $user->id)])
                ->header('Cache-Control', 'no-store, private');
        } catch (\App\Exceptions\UserMutationException $exception) {
            abort(422, $exception->getMessage());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('Subscription secret reset failed', ['exception_type' => get_class($exception), 'exception' => $exception]);
            abort(500, $rotated ? '订阅凭据已更新，请刷新订阅页面获取新链接。' : __('Reset failed'));
        }
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'auto_renewal',
            'remind_expire',
            'remind_traffic'
        ]);

        try {
            app(\App\Services\UserProfileMutationService::class)->updatePreferences((int) $request->user['id'], $updateData);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('User preference update failed', ['exception_type' => get_class($exception), 'exception' => $exception]);
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        $amount = (int)$request->input('transfer_amount');
        DB::transaction(function () use ($request, $amount) {
            $user = User::whereKey($request->user['id'])->lockForUpdate()->first();
            if (!$user) abort(500, __('The user does not exist'));
            if ($amount > $user->commission_balance) abort(500, __('Insufficient commission balance'));

            $order = new Order();
            $order->user_id = $user->id;
            $order->plan_id = 0;
            $order->period = 'deposit';
            $order->type = 9;
            $order->trade_no = Helper::generateOrderNo();
            $order->status = 3;
            $order->total_amount = 0;
            $order->surplus_amount = $amount;
            $order->callback_no = '佣金划转 Commission transfer';
            // Moving existing commission is not a purchase and must not create new referral earnings.
            $order->invite_user_id = null;
            $order->commission_balance = 0;
            $order->actual_commission_balance = 0;
            $order->commission_status = 2;
            $user->commission_balance -= $amount;
            $user->balance += $amount;
            if (!$order->save() || !$user->save()) abort(500, __('Transfer failed'));
        }, 3);

        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $input = $request->validate(['redirect' => 'sometimes|nullable|string|max:500']);
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!is_string($authorization) || $authorization === '') abort(403, '未登录或登陆已过期');
        try { $code = AuthService::quickLoginCodeForAuth($authorization); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { throw $exception; }
        catch (\Throwable $exception) {
            \Log::warning('User quick login failed', ['exception_type' => get_class($exception), 'exception' => $exception]);
            abort(500, __('Token error'));
        }
        return response([
            'data' => Helper::getQuickLoginUrl($code, !empty($input['redirect']) ? $input['redirect'] : 'dashboard')
        ])->header('Cache-Control', 'no-store, private');
    }
}
