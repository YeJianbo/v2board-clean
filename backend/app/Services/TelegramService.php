<?php
namespace App\Services;

use App\Jobs\SendTelegramJob;
use App\Exceptions\TelegramApiException;
use App\Models\User;
use \Curl\Curl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramService
{
    protected $api;
    protected $token;

    public function __construct($token = '')
    {
        $explicitToken = trim((string) $token);
        if ($explicitToken !== '') {
            $this->token = $explicitToken;
        } else {
            $configuredToken = function_exists('admin_setting')
                ? admin_setting('telegram_bot_token', config('v2board.telegram_bot_token', ''))
                : config('v2board.telegram_bot_token', '');
            $this->token = trim((string) $configuredToken);
        }
        $this->api = 'https://api.telegram.org/bot' . $this->token . '/';
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '')
    {
        if ($parseMode === 'markdown') {
            $text = str_replace('_', '\_', $text);
        }
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($parseMode !== '') {
            $params['parse_mode'] = $parseMode;
        }
        $this->request('sendMessage', $params);
    }

    public function approveChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function declineChatJoinRequest(int $chatId, int $userId)
    {
        $this->request('declineChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId
        ]);
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function getBotUsername(): string
    {
        if ($this->token === '') throw new RuntimeException('Telegram Bot Token未配置');
        // Isolate credentials without putting the Bot Token into cache keys or diagnostics.
        $key = 'telegram:bot-username:v1:' . hash_hmac('sha256', $this->token, (string) config('app.key'));
        try {
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') return $cached;
        } catch (\Throwable $exception) {
            $this->logBotInfoCacheFailure('read', $exception);
        }

        $username = $this->getMe()->result->username ?? null;
        if (!is_string($username) || $username === '') throw new RuntimeException('Telegram未返回机器人用户名');
        try {
            Cache::put($key, $username, 60);
        } catch (\Throwable $exception) {
            $this->logBotInfoCacheFailure('write', $exception);
        }
        return $username;
    }

    private function logBotInfoCacheFailure(string $stage, \Throwable $exception): void
    {
        try {
            Log::warning('Telegram bot info cache failed', ['stage' => $stage, 'exception' => $exception]);
        } catch (\Throwable $loggingException) {
            // Public bot information remains usable if optional caching and diagnostics fail.
        }
    }

    public function setWebhook(string $url)
    {
        $commands = $this->discoverCommands(base_path('app/Plugins/Telegram/Commands'));
        $this->setMyCommands($commands);
        return $this->request('setWebhook', [
            'url' => $url
        ]);
    }

    public function discoverCommands(string $directory): array
    {
        $commands = [];

        foreach (glob($directory . '/*.php') as $file) {
            $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');

            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $ref = new \ReflectionClass($className);

                if (
                    $ref->hasProperty('command') &&
                    $ref->hasProperty('description')
                ) {
                    $commandProp = $ref->getProperty('command');
                    $descProp = $ref->getProperty('description');

                    $command = $commandProp->isStatic()
                        ? $commandProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->command;

                    $description = $descProp->isStatic()
                        ? $descProp->getValue()
                        : $ref->newInstanceWithoutConstructor()->description;

                    $commands[] = [
                        'command' => $command,
                        'description' => $description,
                    ];
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        return $commands;
    }
    
    public function setMyCommands(array $commands)
    {
        $this->request('setMyCommands', [
            'commands' => json_encode($commands),
        ]);
    }

    private function request(string $method, array $params = [])
    {
        if ($this->token === '') {
            throw new RuntimeException('Telegram Bot Token未配置');
        }

        $curl = $this->createCurl();
        try {
            $curl->setConnectTimeout(3);
            $curl->setTimeout(10);
            $curl->post($this->api . $method, $params);
            $response = $curl->response;
        } finally {
            $curl->close();
        }
        if (!is_object($response) || !isset($response->ok) || !is_bool($response->ok)) {
            throw new RuntimeException('Telegram请求失败');
        }
        if (!$response->ok) {
            $description = is_string($response->description ?? null) ? $response->description : '未知错误';
            $description = str_replace($this->token, '[redacted]', $description);
            throw new TelegramApiException('来自TG的错误：' . $description, (int) ($response->error_code ?? 0), (int) ($response->parameters->retry_after ?? 0));
        }
        return $response;
    }

    protected function createCurl(): Curl
    {
        return new Curl();
    }

    public function sendMessageWithAdmin(
        $message,
        $isStaff = false,
        string $parseMode = 'markdown',
        bool $synchronously = false
    ): int
    {
        $enabled = function_exists('admin_setting')
            ? admin_setting('telegram_bot_enable', config('v2board.telegram_bot_enable', 0))
            : config('v2board.telegram_bot_enable', 0);
        if (!$enabled || $this->token === '') {
            return 0;
        }

        $users = User::where(function ($query) use ($isStaff) {
            $query->where('is_admin', 1);
            if ($isStaff) {
                $query->orWhere('is_staff', 1);
            }
        })
            ->where('telegram_id', '!=', NULL)
            ->pluck('telegram_id')
            ->all();

        $chatIds = array_merge($users, $this->configuredAdminChatIds());
        $chatIds = array_values(array_unique(array_filter(array_map(function ($chatId) {
            $chatId = trim((string) $chatId);
            return preg_match('/^-?\d+$/', $chatId) ? (int) $chatId : null;
        }, $chatIds))));

        foreach ($chatIds as $chatId) {
            if ($synchronously) {
                $this->sendMessage($chatId, (string) $message, $parseMode);
                continue;
            }

            SendTelegramJob::dispatch($chatId, (string) $message, $parseMode);
        }

        return count($chatIds);
    }

    private function configuredAdminChatIds(): array
    {
        $values = [];
        foreach (['telegram_admin_chat_id', 'telegram_discuss_id', 'telegram_channel_id'] as $key) {
            $value = function_exists('admin_setting')
                ? admin_setting($key, config('v2board.' . $key))
                : config('v2board.' . $key);
            if (is_array($value)) {
                $values = array_merge($values, $value);
                continue;
            }
            if ($value !== null && $value !== '') {
                $values = array_merge($values, preg_split('/[\s,;]+/', (string) $value) ?: []);
            }
        }

        return $values;
    }
}
