<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Models\TicketMessage;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TicketNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 10;
    public $backoff = [30, 120];
    public bool $includeUserDetails = true;

    public function __construct(public int $ticketId, public int $messageId, public ?string $ipAddress, bool $includeUserDetails = true)
    {
        $this->includeUserDetails = $includeUserDetails;
        $this->onQueue('send_telegram');
    }

    public function handle(): void
    {
        if (!admin_setting('telegram_bot_enable', 0) || !admin_setting('telegram_bot_token', '')) return;
        $relations = $this->includeUserDetails ? ['ticket.user'] : ['ticket'];
        $message = TicketMessage::with($relations)->find($this->messageId);
        if (!$message || (int) $message->ticket_id !== $this->ticketId || !$message->ticket) return;
        $ticket = $message->ticket;
        $text = "📮工单提醒 #{$ticket->id}\n———————————————\n";
        if ($this->includeUserDetails && ($user = $ticket->user)) {
            $ip = filter_var($this->ipAddress, FILTER_VALIDATE_IP) ? $this->ipAddress : '未知';
            $planName = $user->plan_id ? Plan::where('id', $user->plan_id)->value('name') : null;
            $expiredAt = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '不限';
            $total = $this->formatTraffic($user->transfer_enable);
            $remaining = $this->formatTraffic(max(0, $user->transfer_enable - $user->u - $user->d));
            $upload = $this->formatTraffic($user->u);
            $download = $this->formatTraffic($user->d);
            $money = $user->balance / 100;
            $commission = $user->commission_balance / 100;
            $location = $this->location();
            $text .= "邮箱：\n{$user->email}\n用户位置：\n{$location}\nIP：\n{$ip}\n"
                . "套餐与流量：\n" . ($planName ?: '未找到套餐信息') . " of {$total}/{$remaining}\n"
                . "上传/下载：\n{$upload}/{$download}\n到期时间：\n{$expiredAt}\n"
                . "余额/佣金余额：\n{$money}/{$commission}\n";
        }
        $text .= "主题：\n{$ticket->subject}\n内容：\n{$message->message}";
        // Ticket text is untrusted; plain text also avoids Telegram Markdown parse failures.
        app(TelegramService::class)->sendMessageWithAdmin(mb_substr($text, 0, 4096), true, '');
    }

    private function location(): string
    {
        $fallback = '无法确定用户地址';
        if (!filter_var($this->ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $fallback;
        try {
            return Cache::remember('ticket_ip_location:' . $this->ipAddress, 86400, function () use ($fallback) {
                try {
                    $response = Http::withOptions(['connect_timeout' => 1, 'allow_redirects' => false])
                        ->timeout(2)->get('http://ip-api.com/json/' . rawurlencode($this->ipAddress), [
                            'fields' => 'status,city,country', 'lang' => 'zh-CN',
                        ]);
                    $data = $response->successful() ? $response->json() : null;
                    if (is_array($data) && ($data['status'] ?? null) === 'success'
                        && is_string($data['city'] ?? null) && is_string($data['country'] ?? null)) {
                        return $data['city'] . ', ' . $data['country'];
                    }
                } catch (\Throwable $error) {
                    // Location is optional and must never prevent the ticket notification.
                }
                return $fallback;
            });
        } catch (\Throwable $error) {
            return $fallback;
        }
    }

    private function formatTraffic($bytes): string
    {
        $gigabytes = (float) $bytes / 1073741824;
        return $gigabytes >= 1 ? round($gigabytes, 2) . 'GB' : round((float) $bytes / 1048576, 2) . 'MB';
    }
}
