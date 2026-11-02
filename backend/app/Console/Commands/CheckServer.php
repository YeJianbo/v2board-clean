<?php

namespace App\Console\Commands;

use App\Services\ServerService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:server';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '节点检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        return $this->checkOffline();
    }

    private function checkOffline()
    {
        $serverService = new ServerService();
        $servers = $serverService->getNodeStatusDetails();
        $telegramService = null;
        $failed = false;
        foreach ($servers as $server) {
            if ($server['parent_id']) continue;
            if ($server['last_check_at'] && (time() - $server['last_check_at']) > 1800) {
                $notificationKey = sprintf('node-offline-notified:%s:%d:%d', $server['type'], $server['id'], $server['last_check_at']);
                if (!Cache::add($notificationKey, true, 3600)) continue;
                $message = sprintf(
                    "节点掉线通知\r\n----\r\n节点名称：%s\r\n节点地址：%s\r\n",
                    $server['name'],
                    $server['host']
                );
                $queued = false;
                try {
                    $telegramService ??= new TelegramService();
                    $queued = $telegramService->sendMessageWithAdmin($message, false, '') > 0;
                } catch (\Throwable $error) {
                    $failed = true;
                    Log::warning('Node offline notification enqueue failed', [
                        'server_type' => $server['type'], 'server_id' => $server['id'],
                        'exception_type' => get_class($error),
                        'exception' => $error,
                    ]);
                } finally {
                    if (!$queued) Cache::forget($notificationKey);
                }
            }
        }
        return $failed ? 1 : 0;
    }
}
