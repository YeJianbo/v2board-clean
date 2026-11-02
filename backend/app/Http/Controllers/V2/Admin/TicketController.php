<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    private function applyFiltersAndSorts(Request $request, $builder)
    {
        if ($request->has('filter')) {
            collect($request->input('filter'))->each(function ($filter) use ($builder) {
                $key = $filter['id'];
                $value = $filter['value'];
                $builder->where(function ($query) use ($key, $value) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else if (is_string($value) && str_contains($value, ':')) {
                        [$operator, $filterValue] = explode(':', $value, 2);
                        $query->where($key, match (strtolower($operator)) {
                            'eq' => '=',
                            'gt' => '>',
                            'gte' => '>=',
                            'lt' => '<',
                            'lte' => '<=',
                            default => 'like',
                        }, strtolower($operator) === 'like' ? "%{$filterValue}%" : $filterValue);
                    } else {
                        $query->where($key, 'like', "%{$value}%");
                    }
                });
            });
        }

        if ($request->has('sort')) {
            collect($request->input('sort'))->each(function ($sort) use ($builder) {
                $key = $sort['id'];
                $value = $sort['desc'] ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }
    public function fetch(Request $request)
    {
        $fields = 'id,user_id,subject,level,status,reply_status,created_at,updated_at';
        $request->validate([
            'id' => 'sometimes|integer|min:1',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'status' => 'sometimes|integer|in:0,1',
            'reply_status' => 'sometimes|array|max:500',
            'reply_status.*' => 'integer|in:0,1',
            'email' => 'sometimes|string|max:255',
            'filter' => 'sometimes|array|max:20',
            'filter.*' => 'array',
            'filter.*.id' => 'required|string|in:' . $fields,
            'filter.*.value' => ['present', function ($attribute, $value, $fail) {
                if (is_array($value) && count($value) > 500) {
                    $fail('单项筛选最多允许 500 个值');
                    return;
                }
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if ((!is_null($item) && !is_scalar($item)) || (is_string($item) && mb_strlen($item) > 255)) {
                        $fail('筛选值必须是长度不超过255的文本、数字或它们组成的列表');
                        return;
                    }
                }
            }],
            'sort' => 'sometimes|array|max:8',
            'sort.*' => 'array',
            'sort.*.id' => 'required|string|in:' . $fields,
            'sort.*.desc' => 'required|boolean',
        ]);
        if ($request->input('id')) {
            return $this->fetchTicketById($request);
        } else {
            return $this->fetchTickets($request);
        }
    }

    /**
     * Summary of fetchTicketById
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function fetchTicketById(Request $request)
    {
        $ticket = Ticket::with('messages', 'user')->find($request->input('id'));

        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }
        $result = $ticket->toArray();
        $result['user'] = $ticket->user ? UserController::transformUserData($ticket->user) : null;

        return $this->success($result);
    }

    /**
     * Summary of fetchTickets
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    private function fetchTickets(Request $request)
    {
        $ticketModel = Ticket::with('user')
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->has('reply_status'), function ($query) use ($request) {
                $query->whereIn('reply_status', $request->input('reply_status'));
            })
            ->when($request->has('email'), function ($query) use ($request) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('email', $request->input('email'));
                });
            });

        $this->applyFiltersAndSorts($request, $ticketModel);
        $pageSize = (int) $request->input('pageSize', 10);
        $current = (int) $request->input('current', 1);
        $total = (clone $ticketModel)->reorder()->count();
        $tickets = ($current - 1) * $pageSize < $total
            ? $ticketModel->latest('updated_at')->orderByDesc('id')->forPage($current, $pageSize)->get()
            : collect();

        $onlineStates = UserController::getOnlineStates($tickets->pluck('user.id')->filter()->all());
        $items = $tickets->map(function ($ticket) use ($onlineStates) {
            $ticketData = $ticket->attributesToArray();
            $ticketData['user'] = $ticket->user ? UserController::transformUserData($ticket->user, $onlineStates[(int) $ticket->user->id]) : null;
            return $ticketData;
        })->all();

        return response([
            'data' => $items,
            'total' => $total
        ]);
    }

    public function reply(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1|max:2147483647',
            'message' => 'required|string'
        ], [
            'id.required' => '工单ID不能为空',
            'message.required' => '消息不能为空'
        ]);
        $adminId = (int) $request->input('user.id');
        if ($adminId <= 0) abort(403, '未登录或登陆已过期');
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $adminId
        );
        return $this->success(true);
    }

    public function close(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1|max:2147483647'
        ], [
            'id.required' => '工单ID不能为空'
        ]);
        try {
            DB::transaction(function () use ($request): void {
                $ticket = Ticket::whereKey($request->input('id'))->lockForUpdate()->firstOrFail();
                $ticket->status = Ticket::STATUS_CLOSED;
                if (!$ticket->save()) throw new \RuntimeException('Ticket close save rejected');
            }, 3);
            return $this->success(true);
        } catch (ModelNotFoundException $e) {
            return $this->fail([400202, '工单不存在']);
        } catch (\Throwable $e) {
            Log::error('Ticket close failed', ['ticket_id' => (int) $request->input('id'), 'exception' => $e]);
            return $this->fail([500101, '关闭失败']);
        }
    }

    public function show($ticketId)
    {
        $ticket = Ticket::with([
            'user',
            'messages' => function ($query) {
                $query->with(['user']);
            }
        ])->findOrFail($ticketId);


        return response()->json([
            'data' => $ticket
        ]);
    }
}
