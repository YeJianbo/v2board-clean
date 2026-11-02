<?php
namespace App\Services;


use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketService {
    public function reply($ticket, $message, $userId)
    {
        return DB::transaction(function () use ($ticket, $message, $userId) {
            $current = Ticket::whereKey($ticket->id)->lockForUpdate()->first();
            if (!$current || (int) $current->user_id !== (int) $userId
                || (int) $current->status !== Ticket::STATUS_OPEN) return false;
            $lastMessage = TicketMessage::where('ticket_id', $current->id)->latest('id')->first();
            if ($lastMessage && (int) $lastMessage->user_id === (int) $userId) return false;
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId, 'ticket_id' => $current->id, 'message' => $message,
            ]);
            $current->reply_status = Ticket::REPLY_PENDING;
            if (!$ticketMessage->exists || !$ticketMessage->getKey() || !$current->save()) throw new \RuntimeException('Ticket reply failed');
            return $ticketMessage;
        }, 3);
    }

    public function replyByAdmin($ticketId, $message, $userId):void
    {
        DB::transaction(function () use ($ticketId, $message, $userId) {
            $ticket = Ticket::whereKey($ticketId)->lockForUpdate()->first();
            if (!$ticket) abort(500, '工单不存在');
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId, 'ticket_id' => $ticket->id, 'message' => $message,
            ]);
            $ticket->status = Ticket::STATUS_OPEN;
            $ticket->reply_status = Ticket::REPLY_REPLIED;
            if (!$ticketMessage->exists || !$ticketMessage->getKey() || !$ticket->save()) abort(500, '工单回复失败');
            DB::afterCommit(function () use ($ticket, $ticketMessage) {
                $this->sendEmailNotify($ticket, $ticketMessage);
            });
        }, 3);
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        $claimed = false;
        try {
            $user = User::find($ticket->user_id, ['id', 'email']);
            if (!$user || !$user->email) return;
            $claimed = Cache::add($cacheKey, 1, 1800);
            if (!$claimed) return;
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . config('v2board.app_name', 'V2Board') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}",
                    'content_mode' => 'text',
                ]
            ]);
        } catch (\Throwable $error) {
            if ($claimed) {
                try { Cache::forget($cacheKey); } catch (\Throwable $cacheError) {}
            }
            Log::warning('Ticket reply notification could not be queued', [
                'ticket_id' => (int) $ticket->id, 'exception' => get_class($error),
            ]);
        }
    }
}
