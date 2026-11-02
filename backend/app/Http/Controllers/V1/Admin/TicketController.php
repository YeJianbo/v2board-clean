<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'id' => 'sometimes|nullable|integer|min:1|max:2147483647',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'status' => 'sometimes|nullable|integer|in:0,1',
            'reply_status' => 'sometimes|nullable|array|max:500',
            'reply_status.*' => 'integer|in:0,1',
            'email' => 'sometimes|nullable|string|max:255',
        ]);
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->first();
            if (!$ticket) {
                abort(500, '工单不存在');
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->orderBy('id')->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = true;
                } else {
                    $ticket['message'][$i]['is_me'] = false;
                }
            }
            return response([
                'data' => $ticket
            ]);
        }
        $current = (int) $request->input('current', 1);
        $pageSize = max(10, (int) $request->input('pageSize', 10));
        $model = Ticket::orderBy('updated_at', 'DESC')->orderByDesc('id');
        if ($request->input('status') !== NULL) {
            $model->where('status', $request->input('status'));
        }
        if ($request->input('reply_status') !== NULL) {
            $model->whereIn('reply_status', $request->input('reply_status'));
        }
        if ($request->input('email') !== NULL && $request->input('email') !== '') {
            $model->whereHas('user', function ($query) use ($request) {
                $query->where('email', $request->input('email'));
            });
        }
        $total = $model->count();
        $res = ($current - 1) * $pageSize < $total
            ? $model->forPage($current, $pageSize)->get()
            : collect();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        if (empty($request->input('message'))) {
            abort(500, '消息不能为空');
        }
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $request->user['id']
        );
        return response([
            'data' => true
        ]);
    }

    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->first();
        if (!$ticket) {
            abort(500, '工单不存在');
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, '关闭失败');
        }
        return response([
            'data' => true
        ]);
    }
}
