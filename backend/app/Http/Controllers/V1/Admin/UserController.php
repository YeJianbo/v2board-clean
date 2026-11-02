<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use App\Utils\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function resetSecret(Request $request)
    {
        $input = $request->validate(['id' => 'required|integer|min:1|max:2147483647', 'mode' => 'sometimes|required|in:grace,immediate,revoke']);
        try {
            app(\App\Services\UserMutationService::class)->resetSecret($request, (int) $input['id'], true);
            return response(['data' => true]);
        } catch (\App\Exceptions\UserMutationException $exception) {
            abort(500, $exception->getMessage());
        } catch (\App\Services\Plugin\InterceptResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('User secret reset failed', ['exception_type' => get_class($exception)]);
            abort(500, '重置失败');
        }
    }

    private function filter(Request $request, $builder)
    {
        $filters = $request->input('filter');
        if ($filters) {
            foreach ($filters as $k => $filter) {
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                if ($filter['key'] === 'd' || $filter['key'] === 'transfer_enable') {
                    $filter['value'] = $filter['value'] * 1073741824;
                }
                if ($filter['key'] === 'invite_by_email') {
                    $inviteUserId = (int) User::where('email', $filter['condition'], $filter['value'])->value('id');
                    $builder->where('invite_user_id', $inviteUserId);
                    unset($filters[$k]);
                    continue;
                }
                if ($filter['key'] === 'plan_id' && $filter['value'] == 'null') {
                    $builder->whereNull('plan_id');
                    continue;
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    public function fetch(UserFetch $request)
    {
        $current = (int) $request->input('current', 1);
        $pageSize = max(10, (int) $request->input('pageSize', 10));
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $userModel = User::select(
            DB::raw('*'),
            DB::raw('(u+d) as total_used')
        )
            ->orderBy($sort, $sortType);
        $this->filter($request, $userModel);
        $total = $userModel->count();
        $res = $userModel->forPage($current, $pageSize)
            ->get();
        $planIds = $res->pluck('plan_id')->filter()->unique()->all();
        $plans = $planIds ? Plan::whereIn('id', $planIds)->pluck('name', 'id') : collect();
        $online = \App\Services\UserOnlineStateService::forUsers($res->pluck('id')->all());
        foreach ($res as $user) {
            if ($plans->has($user->plan_id)) $user['plan_name'] = $plans->get($user->plan_id);
            $state = $online[(int) $user->id];
            $user['alive_ip'] = $state['count'];
            $user['online_count'] = $state['count'];
            $user['ips'] = $state['ips'];
            $user['subscribe_url'] = Helper::getSubscribeUrl($user->token, (int) $user->id);
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function getUserInfoById(Request $request)
    {
        $input = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        $user = User::find((int) $input['id']);
        if (!$user) abort(500, '用户不存在');
        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id, ['id', 'email']);
        }
        return response([
            'data' => $user
        ]);
    }

    public function update(UserUpdate $request)
    {
        try {
            app(\App\Services\UserMutationService::class)->update($request, $request->validated(), true);
            return response(['data' => true]);
        } catch (\App\Exceptions\UserMutationException $exception) {
            $status = $exception->getCode() === 422 ? 422 : ($exception->getMessage() === '邀请人不存在' ? 404 : 500);
            abort($status, $exception->getMessage());
        } catch (\App\Services\Plugin\InterceptResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('User update failed', ['exception_type' => get_class($exception)]);
            abort(500, '保存失败');
        }
    }

    public function dumpCSV(Request $request)
    {
        $userModel = User::query()->with('plan:id,name')->orderBy('id', 'asc')->select([
            'id', 'email', 'balance', 'commission_balance', 'transfer_enable', 'device_limit',
            'u', 'd', 'expired_at', 'plan_id', 'token',
        ]);
        $this->filter($request, $userModel);
        $maxId = (int) (clone $userModel)->reorder()->max('id');
        $userModel->where('id', '<=', $maxId);
        return Csv::download('users_' . date('Y-m-d_His') . '.csv', function ($output) use ($userModel) {
            Csv::writeRow($output, ['邮箱', '余额', '推广佣金', '总流量', '设备数限制', '剩余流量', '套餐到期时间', '订阅计划', '订阅地址']);
            $userModel->chunkById(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    Csv::writeRow($output, [
                        Csv::text($user->email), $user->balance / 100, $user->commission_balance / 100,
                        $user->transfer_enable / 1073741824, $user->device_limit ?: null,
                        ($user->transfer_enable - $user->u - $user->d) / 1073741824,
                        $user->expired_at === null ? '长期有效' : date('Y-m-d H:i:s', $user->expired_at),
                        Csv::text($user->plan->name ?? '无订阅'), Csv::text(Helper::getSubscribeUrl($user->token, (int) $user->id)),
                    ]);
                }
            });
        });
    }

    public function generate(UserGenerate $request)
    {
        try {
            $input = $request->validated();
            if (!empty($input['async'])) {
                $task = app(\App\Services\UserGenerationTaskService::class)->start((int) ($request->user['id'] ?? 0), $input, true);
                return response(['data' => $task]);
            }
            $generator = app(\App\Services\UserGenerationService::class);
            $result = $generator->execute($generator->specification($input, true));
            if (!$result['bulk']) return response(['data' => true]);
            return $generator->csv($result['data']);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            abort(500, collect($exception->errors())->flatten()->first());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('User generation failed', ['exception_type' => get_class($exception)]);
            abort(500, '生成失败');
        }
    }

    public function sendMail(UserSendMail $request)
    {
        $this->validateBulkFilters($request);
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        app(\App\Services\LegacyUserBulkMailService::class)->enqueue($builder, [
            'subject' => $request->input('subject'),
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => $request->input('content'),
            ],
        ]);

        return response([
            'data' => true
        ]);
    }

    public function ban(Request $request)
    {
        $this->validateBulkFilters($request);
        $builder = User::query();
        $this->filter($request, $builder);
        try {
            app(\App\Services\LegacyUserBulkMutationService::class)->ban($builder);
        } catch (\Throwable $exception) {
            \Log::warning('Legacy bulk user ban failed', ['exception_type' => get_class($exception)]);
            abort(500, '处理失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function allDel(Request $request)
    {
        $this->validateBulkFilters($request);
        $builder = User::query();
        $this->filter($request, $builder);
        try {
            app(\App\Services\LegacyUserBulkMutationService::class)->delete($builder);
        } catch (\Throwable $exception) {
            \Log::warning('Legacy bulk user deletion failed', ['exception_type' => get_class($exception)]);
            abort(500, '批量删除用户信息失败');
        }  

        return response([
            'data' => true
        ]);
    }

    public function delUser(Request $request)
    {
        $input = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(\App\Services\UserDeletionService::class)->delete($request, (int) $input['id'], true);
        } catch (\App\Exceptions\UserMutationException $exception) {
            abort(500, $exception->getMessage());
        } catch (\Throwable $exception) {
            \Log::warning('User deletion failed', ['exception_type' => get_class($exception)]);
            abort(500, '删除用户失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function validateBulkFilters(Request $request): void
    {
        $input = $request->validate([
            'filter' => 'sometimes|nullable|array|max:20',
            'filter.*.key' => 'required|in:id,email,transfer_enable,d,expired_at,uuid,token,invite_by_email,invite_user_id,plan_id,banned,remarks,is_admin',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=',
            'filter.*.value' => ['required', function ($attribute, $value, $fail) {
                if (!is_scalar($value) || (is_string($value) && strlen($value) > 500)) $fail('筛选值格式错误或过长');
            }],
        ]);
        foreach ($input['filter'] ?? [] as $index => $filter) {
            if (in_array($filter['key'], ['d', 'transfer_enable'], true) && !is_numeric($filter['value'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['filter.'.$index.'.value' => '流量筛选值必须为数字']);
            }
        }
    }

    public function setInviteUser(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:App\\Models\\User,id',
            'invite_user_email' => 'nullable|email:strict',
        ]);

        $user = User::find($params['id']);
        $inviteUserId = null;

        if (!empty($params['invite_user_email'])) {
            $inviteUser = User::where('email', $params['invite_user_email'])->first();
            if (!$inviteUser) {
                abort(404, '邀请人不存在');
            }
            if ((int) $inviteUser->id === (int) $user->id) {
                abort(422, '不能将用户自己设为邀请人');
            }
            $inviteUserId = (int) $inviteUser->id;
        }

        $user->invite_user_id = $inviteUserId;
        if (!$user->save()) {
            abort(500, '保存失败');
        }

        return response(['data' => true]);
    }
}
