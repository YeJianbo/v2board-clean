<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Jobs\TicketNotificationJob;
use App\Models\User;
use App\Models\Order;
use App\Services\TicketService;
use App\Services\UserService;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate(['id' => 'sometimes|nullable|integer|min:1']);
        $userId = $request->user['id'];
        $ticketId = $request->input('id');

        if ($ticketId) {
            $ticket = Ticket::where('id', $ticketId)
                ->where('user_id', $userId)
                ->firstOrFail();

            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->orderBy('id')->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ((int) $ticket['message'][$i]['user_id'] !== (int) $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = false;
                } else {
                    $ticket['message'][$i]['is_me'] = true;
                }
            }

            return response(['data' => $ticket]);

        }
        $ticket = Ticket::where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();
        return response([
            'data' => $ticket
        ]);
    }

    public function save(TicketSave $request)
    {
        try {
            [$ticket, $ticketMessage] = DB::transaction(function () use ($request) {
                // Lock a stable row even when this user has no ticket yet.
                $user = User::whereKey($request->user['id'])->lockForUpdate()->firstOrFail();
                if (Ticket::where('status', Ticket::STATUS_OPEN)->where('user_id', $request->user['id'])->exists()) {
                    throw new \Exception(__('There are other unresolved tickets'));
                }
                switch (config('v2board.ticket_status', 0)) {
                    case 0:
                        break;
                    case 1:
                        if (!Order::where('user_id', $request->user['id'])->whereIn('status', [3, 4])->exists()) {
                            throw new \Exception(__('请先购买套餐'));
                        }
                        break;
                    case 2:
                        throw new \Exception(__('当前套餐不允许发起工单'));
                    default:
                        throw new \Exception(__('未知的工单状态'));
                }
                if ((bool) config('v2board.ticket_active_subscription_required', false)
                    && !(new UserService())->isAvailable($user)) {
                    throw new \RuntimeException(__('A valid subscription is required to open a ticket'));
                }
                $ticket = Ticket::create($request->only(['subject', 'level']) + ['user_id' => $request->user['id']]);
                if (!$ticket->exists || !$ticket->getKey()) throw new \RuntimeException(__('Failed to open ticket'));
                $ticketMessage = TicketMessage::create([
                    'user_id' => $request->user['id'], 'ticket_id' => $ticket->id, 'message' => $request->input('message'),
                ]);
                if (!$ticketMessage->exists || !$ticketMessage->getKey()) throw new \RuntimeException(__('Failed to open ticket'));
                return [$ticket, $ticketMessage];
            }, 3);
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
        $this->sendNotify($ticket, $ticketMessage, $request);
        return response(['data' => true]);
    }

    public function reply(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1', 'message' => 'required|string']);
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, __('Ticket does not exist'));
        }
        if ($ticket->status) {
            abort(500, __('The ticket is closed and cannot be replied'));
        }
        if (($lastMessage = $this->getLastMessage($ticket->id)) && (int) $request->user['id'] === (int) $lastMessage->user_id) {
            abort(500, __('Please wait for the technical enginneer to reply'));
        }
        $ticketService = new TicketService();
        $ticketMessage = $ticketService->reply($ticket, $request->input('message'), $request->user['id']);
        if (!$ticketMessage) {
            abort(500, __('Ticket reply failed'));
        }
        $this->sendNotify($ticket, $ticketMessage, $request);
        return response([
            'data' => true
        ]);
    }


    public function close(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            $result = DB::transaction(function () use ($request) {
                $ticket = Ticket::where('id', $request->input('id'))
                    ->where('user_id', $request->user['id'])->lockForUpdate()->first();
                if (!$ticket) return null;
                $ticket->status = Ticket::STATUS_CLOSED;
                if (!$ticket->save()) throw new \RuntimeException('User ticket close save rejected.');
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            Log::warning('User ticket close failed', ['ticket_id' => (int) $request->input('id'),
                'user_id' => (int) $request->user['id'], 'exception' => $exception]);
            abort(500, __('Close failed'));
        }
        if ($result === null) abort(500, __('Ticket does not exist'));
        return response([
            'data' => true
        ]);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int)config('v2board.withdraw_close_enable', 0)) {
            abort(500, 'user.ticket.withdraw.not_support_withdraw');
        }
        if (
			!in_array(
				$request->input('withdraw_method'),
				config(
					'v2board.commission_withdraw_method',
					Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT
				)
			)
		) {
            abort(500, __('Unsupported withdrawal method'));
        }
        $user = User::find($request->user['id']);
        $limit = config('v2board.commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            abort(500, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit]));
        }
        $message = sprintf(
			"%s\r\n%s",
            __('Withdrawal method') . "：" . $request->input('withdraw_method'),
            __('Withdrawal account') . "：" . $request->input('withdraw_account')
        );
        [$ticket, $ticketMessage] = DB::transaction(function () use ($request, $message) {
            $ticket = Ticket::create([
                'subject' => __('[Commission Withdrawal Request] This ticket is opened by the system'),
                'level' => 2,
                'user_id' => $request->user['id'],
            ]);
            if (!$ticket->exists || !$ticket->getKey()) abort(500, __('Failed to open ticket'));
            $ticketMessage = TicketMessage::create([
                'user_id' => $request->user['id'],
                'ticket_id' => $ticket->id,
                'message' => $message,
            ]);
            if (!$ticketMessage->exists || !$ticketMessage->getKey()) abort(500, __('Failed to open ticket'));
            return [$ticket, $ticketMessage];
        }, 3);
        $this->sendNotify($ticket, $ticketMessage, $request, false);
        return response([
            'data' => true
        ]);
    }

    private function sendNotify(Ticket $ticket, TicketMessage $message, Request $request, bool $includeUserDetails = true): void
    {
        if (!admin_setting('telegram_bot_enable', 0) || !admin_setting('telegram_bot_token', '')) return;
        try {
            TicketNotificationJob::dispatch((int) $ticket->id, (int) $message->id,
                $includeUserDetails ? $request->ip() : null, $includeUserDetails)->afterCommit();
        } catch (\Throwable $error) {
            Log::warning('Ticket admin notification could not be queued', [
                'ticket_id' => (int) $ticket->id, 'exception' => get_class($error),
            ]);
        }
    }
}
