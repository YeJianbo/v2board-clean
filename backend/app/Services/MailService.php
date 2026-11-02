<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class MailService
{
    public static function sendEmail(array $params, bool $throwOnFailure = false): array
    {
        $name = $params['template_name'];
        $view = 'mail.' . admin_setting('email_template', 'default') . '.' . $name;
        $log = ['email' => $params['email'], 'subject' => $params['subject'], 'template_name' => $view, 'error' => null];
        try {
            self::configureMailer();
            $values = self::navigationValues($name, $params['template_value'] ?? []);
            $variables = (array) ($values['vars'] ?? []) + $values;
            if (isset($values['content'])) {
                $isText = ($values['content_mode'] ?? null) === 'text';
                $content = self::replaceVariables((string) $values['content'], $variables, !$isText);
                $values['content'] = $isText ? htmlspecialchars($content, ENT_QUOTES, 'UTF-8') : $content;
                $variables['content'] = $values['content'];
            }
            $template = Cache::remember("mail_template:{$name}", 300, function () use ($name) {
                // An empty array also caches the legacy installation's missing table/template.
                if (!Schema::hasTable((new MailTemplate())->getTable())) return [];
                return MailTemplate::where('name', $name)->first(['subject', 'content'])?->toArray() ?? [];
            });
            $subject = self::replaceVariables($template['subject'] ?? $params['subject'], $variables, false);
            $subject = str_replace(["\r", "\n"], ' ', $subject);
            $log['subject'] = $subject;
            $callback = static function ($message) use ($params, $subject) {
                $message->to($params['email'])->subject($subject);
            };
            if ($template) {
                // Database templates use placeholders, never executable Blade/PHP.
                $html = self::replaceVariables($template['content'], $variables, true, true);
                $log['template_name'] = 'mail_template.' . $name;
                Mail::html($html, $callback);
            } else {
                Mail::send($view, $values, $callback);
            }
        } catch (\Throwable $error) {
            $log['error'] = self::safeError($error->getMessage());
        }

        try {
            MailLog::create($log);
        } catch (\Throwable $error) {
            // A delivered message must not be retried only because its log insert failed.
            Log::warning('Mail delivery result could not be recorded', ['template' => $name, 'exception' => get_class($error)]);
        }
        if ($throwOnFailure && $log['error'] !== null) throw new \RuntimeException($log['error']);
        $prefix = config('mail.driver') ? 'mail' : 'mail.mailers.' . config('mail.default', 'smtp');
        $log['config'] = [
            'driver' => config('mail.driver') ?: config('mail.default'),
            'host' => config($prefix . '.host'), 'port' => config($prefix . '.port'),
            'encryption' => config($prefix . '.encryption'),
            'from' => ['address' => config('mail.from.address'), 'name' => config('mail.from.name')],
        ];
        return $log;
    }

    private static function navigationValues(string $name, array $values): array
    {
        if (isset(MailTemplate::TEMPLATES[$name])) {
            $siteUrl = rtrim((string) admin_setting('app_url', ''), '/');
            $navigationUrl = rtrim((string) ($values['url'] ?? ''), '/');
            // Upgrade site-root navigation, preserving explicit destinations and vars overrides.
            if ($navigationUrl === '' || $navigationUrl === $siteUrl) {
                $values['url'] = Helper::getUserFrontendUrl();
            }
        }
        return $values;
    }

    private static function configureMailer(): void
    {
        if (!admin_setting('email_host')) return;
        $prefix = config('mail.driver') ? 'mail' : 'mail.mailers.smtp';
        $settings = [];
        foreach (['host', 'port', 'encryption', 'username', 'password'] as $field) {
            $key = $prefix . '.' . $field;
            $settings[$key] = admin_setting('email_' . $field, config($key));
        }
        $settings['mail.from.address'] = admin_setting('email_from_address', config('mail.from.address'));
        $settings['mail.from.name'] = admin_setting('app_name', 'PanelCore');
        $changed = false;
        foreach ($settings as $key => $value) {
            if (config($key) !== $value) {
                config([$key => $value]);
                $changed = true;
            }
        }
        if ($changed) Mail::purge();
    }

    private static function replaceVariables(string $text, array $variables, bool $escape, bool $contentHtml = false): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', static function ($match) use ($variables, $escape, $contentHtml) {
            $key = $match[1];
            if (!array_key_exists($key, $variables) || !is_scalar($variables[$key])) return $match[0];
            $value = (string) $variables[$key];
            if ($contentHtml && $key === 'content') return nl2br($value);
            return $escape ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, $text);
    }

    private static function safeError(string $message): string
    {
        $prefix = config('mail.driver') ? 'mail' : 'mail.mailers.smtp';
        $password = (string) config($prefix . '.password', '');
        $username = (string) config($prefix . '.username', '');
        if ($password !== '') {
            $message = str_replace([$password, base64_encode($password), base64_encode("\0{$username}\0{$password}")], '[redacted]', $message);
        }
        return $message;
    }

    public function remindTraffic (User $user)
    {
        if (!$user->remind_traffic) return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (!Cache::add($flag, 1, 24 * 3600)) return;
        try {
            Bus::dispatch(new SendEmailJob([
                'email' => $user->email,
                'subject' => __('The traffic usage in :app_name has reached 95%', [
                    'app_name' => config('v2board.app_name', 'V2board')
                ]),
                'template_name' => 'remindTraffic',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url')
                ]
            ]));
        } catch (\Throwable $error) {
            try {
                Cache::forget($flag);
            } catch (\Throwable $cleanupError) {
                Log::warning('Traffic reminder cooldown could not be cleared', [
                    'user_id' => (int) $user->id, 'exception' => get_class($cleanupError),
                ]);
            }
            throw $error;
        }
    }

    public function remindExpire(User $user)
    {
        if (!($user->expired_at !== NULL && ($user->expired_at - 86400) < time() && $user->expired_at > time())) return;
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
               'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url')
            ]
        ]);
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud) return false;
        if (!$transfer_enable) return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 95) return false;
        if ($percentage >= 100) return false;
        return true;
    }
}
