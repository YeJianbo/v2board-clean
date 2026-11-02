<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\RegistrationPersistenceService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;

class AuthController extends Controller
{
    public function register(AuthRegister $request)
    {
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }
        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                abort(500, __('You must use the invitation code to register'));
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = is_string($email) ? strtolower(trim($email)) : '';
        if ((int)config('v2board.email_verify', 0)) {
            $inputCode = $request->input('email_code');
            if (!is_string($inputCode) || !preg_match('/^\d{6}$/', $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
            $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
            if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        $password = $request->input('password');
        if (User::where('email', $email)->exists()) {
            abort(500, __('Email already exists'));
        }
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $created = false;
        try {
            $user = app(RegistrationPersistenceService::class)->save($user, $request);
            $created = true;
            return response()->json(['data' => (new AuthService($user))->generateAuthData($request)]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { throw $exception; }
        catch (\Throwable $exception) {
            \Log::warning('Registration failed', ['exception_type' => get_class($exception)]);
            abort(500, $created ? '账户已创建，请直接登录；若登录失败，请稍后重试。' : __('Register failed'));
        }
    }

    public function login(AuthLogin $request, bool $adminOnly = false)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        $passwordLimitEnabled = (bool)(int)config('v2board.password_limit_enable', 1);
        $passwordLimitKey = CacheKey::get('PASSWORD_ERROR_LIMIT', strtolower(trim($email)));
        if ($passwordLimitEnabled) {
            $passwordErrorCount = (int)Cache::get($passwordLimitKey, 0);
            if ($passwordErrorCount >= max(1, (int)config('v2board.password_limit_count', 5))) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user || !Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ($passwordLimitEnabled) {
                // Preserve the first failure's TTL and avoid lost increments under concurrent requests.
                Cache::add($passwordLimitKey, 0, 60 * max(1, (int)config('v2board.password_limit_expire', 60)));
                Cache::increment($passwordLimitKey);
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        if ($adminOnly && !$user->is_admin) abort(403, 'Admin session required');

        if ($passwordLimitEnabled) {
            Cache::forget($passwordLimitKey);
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function loginWithMailLink(Request $request)
    {
        if (!(int) admin_setting('login_with_mail_link_enable')) abort(404);
        $input = $request->validate([
            'email' => 'bail|required|string|max:64|email:strict',
            'redirect' => 'sometimes|nullable|string|max:500',
            'recaptcha_data' => 'sometimes|nullable|string|max:4096',
        ]);
        if ((int) config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            if (!$recaptcha->verify($request->input('recaptcha_data'))->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        try {
            [$success, $result] = app(MailLinkService::class)->handleMailLink($input['email'], $input['redirect'] ?? null);
        } catch (\Throwable $exception) {
            \Log::warning('Mail login request failed', ['exception_type' => get_class($exception)]);
            abort(503, '邮件登录服务暂时不可用，请稍后重试。');
        }
        if (!$success) abort($result[0], $result[1] ?? '');
        return response(['data' => $result])->header('Cache-Control', 'no-store, private');
    }

    public function token2Login(Request $request)
    {
        $input = $request->validate(['token' => 'sometimes|string|max:128', 'verify' => 'sometimes|string|max:128', 'redirect' => 'sometimes|nullable|string|max:500']);
        if (!empty($input['token'])) {
            return redirect()->to(Helper::getQuickLoginUrl($input['token'], $input['redirect'] ?? 'dashboard'))
                ->header('Cache-Control', 'no-store, private');
        }
        if (empty($input['verify'])) abort(500, __('Token error'));
        try { return response(['data' => AuthService::redeemQuickLoginCode($input['verify'], $request)]); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { throw $exception; }
        catch (\Throwable $exception) {
            \Log::warning('Quick login failed', ['exception_type' => get_class($exception)]);
            abort(500, __('Token error'));
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!is_string($authorization) || $authorization === '') abort(403, '未登录或登陆已过期');
        $input = $request->validate(['redirect' => 'sometimes|nullable|string|max:500']);
        $code = AuthService::quickLoginCodeForAuth($authorization);
        return response(['data' => Helper::getQuickLoginUrl($code, $input['redirect'] ?? 'dashboard')])
            ->header('Cache-Control', 'no-store, private');
    }

    public function forget(AuthForget $request)
    {
        try {
            app(\App\Services\Auth\PasswordService::class)->reset($request->input('email'), $request->input('email_code'), $request->input('password'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { throw $exception; }
        catch (\Throwable $exception) {
            \Log::warning('Password reset failed', ['exception_type' => get_class($exception)]);
            abort(500, __('Reset failed'));
        }
        return response(['data' => true]);
    }
}
