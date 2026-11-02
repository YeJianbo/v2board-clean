<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;
use Illuminate\Support\Facades\RateLimiter;

class CommController extends Controller
{
    private function isEmailVerify()
    {
        return response([
            'data' => (int)config('v2board.email_verify', 0) ? 1 : 0
        ]);
    }

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($ip, 60);

        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = strtolower(trim((string)$email));
        $isforget = $request->input('isforget');
        $email_exists = User::where('email', $email)->exists();
        //检查是否在白名单内
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        // 检查是否是gmail别名邮箱
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if (isset($isforget)) {
            if ($isforget == 0 && $email_exists) {
                abort(500, __('This email is registered'));
            } 
            if ($isforget == 1 && !$email_exists) {
                abort(500, __('This email is not registered in the system'));
            }
        }
        // Registration and password resets consume the challenge under this same lock.
        $response = Cache::lock('auth:password-reset:' . $cacheKeyEmail, 120)->get(function () use ($email, $cacheKeyEmail) {
            $cooldownKey = CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $cacheKeyEmail);
            if (Cache::get($cooldownKey)) {
                abort(500, __('Email verification code has been sent, please request again later'));
            }
            $code = (string) random_int(100000, 999999);
            $subject = config('v2board.app_name', 'V2Board') . __('Email verification code');
            // Keep the reservation even if enqueue outcome is uncertain, avoiding immediate duplicate jobs.
            if (!Cache::add($cooldownKey, time(), 60)) {
                abort(503, '验证码服务暂时不可用，请稍后重试。');
            }

            SendEmailJob::dispatch([
                'email' => $email,
                'subject' => $subject,
                'template_name' => 'verify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'code' => $code,
                    'url' => config('v2board.app_url')
                ]
            ]);

            if (!Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail), $code, 300)) {
                abort(503, '验证码服务暂时不可用，请稍后重试。');
            }
            return response(['data' => true]);
        });
        if ($response === false) abort(429, __('Too many requests, please try again later.'));
        return $response;
    }

    public function pv(Request $request)
    {
        $params = $request->validate(['invite_code' => 'nullable|string|max:64']);
        if (isset($params['invite_code'])) {
            // Preserve the single-match behavior even when historical codes are duplicated.
            InviteCode::where('code', $params['invite_code'])->limit(1)->increment('pv');
        }

        return response([
            'data' => true
        ]);
    }

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
