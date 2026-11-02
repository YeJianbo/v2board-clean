<?php

namespace App\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class MailLinkService
{
    /**
     * 处理邮件链接登录逻辑
     *
     * @param string $email 用户邮箱
     * @param string|null $redirect 重定向地址
     * @return array 返回处理结果
     */
    public function handleMailLink(string $email, ?string $redirect = null): array
    {
        if (!(int) admin_setting('login_with_mail_link_enable')) {
            return [false, [404, null]];
        }

        $email = strtolower(trim($email));
        $cooldownKey = CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email);
        // Reserve before lookup, including unknown accounts, without a check/write race.
        if (!Cache::add($cooldownKey, time(), 60)) {
            return Cache::get($cooldownKey) !== null
                ? [false, [429, __('Sending frequently, please try again later')]]
                : [false, [503, '邮件登录服务暂时不可用，请稍后重试。']];
        }

        $user = User::byEmail($email)->first();
        if (!$user || (int) $user->banned === 1) {
            return [true, true]; // 成功但用户不存在，保护用户隐私
        }

        try {
            $code = AuthService::mailLoginCodeForUser($user);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            if (in_array($exception->getMessage(), [__('Your account has been suspended'), __('Incorrect email or password')], true)) {
                return [true, true];
            }
            throw $exception;
        }
        try {
            $this->sendMailLinkEmail($user, Helper::getQuickLoginUrl($code, $redirect ?: 'dashboard'));
        } catch (\Throwable $exception) {
            // Enqueue may be uncertain; invalidate the ticket but retain the cooldown.
            try { Cache::forget(CacheKey::get('TEMP_TOKEN', $code)); } catch (\Throwable $ignored) {}
            throw $exception;
        }

        return [true, true];
    }

    /**
     * 发送邮件链接登录邮件
     *
     * @param User $user 用户对象
     * @param string $link 登录链接
     * @return void
     */
    private function sendMailLinkEmail(User $user, string $link): void
    {
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => admin_setting('app_name', 'PanelCore')
            ]),
            'template_name' => 'mailLogin',
            'template_value' => [
                'name' => admin_setting('app_name', 'PanelCore'),
                'link' => $link,
                'url' => admin_setting('app_url')
            ]
        ]);
    }

}
