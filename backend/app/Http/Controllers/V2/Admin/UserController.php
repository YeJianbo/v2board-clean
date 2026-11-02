<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\StatUser;
use App\Models\StatUserServer;
use App\Models\StatUserServerHour;
use App\Models\StatUserServerMinute;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuthService;
use App\Services\NodeSyncService;
use App\Services\Plugin\HookManager;
use App\Traits\QueryOperators;
use App\Utils\Helper;
use App\Utils\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function getQuickLoginUrl(Request $request)
    {
        $input = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        abort_unless((bool) config('v2board.user_frontend_enable', 1), 422, '用户前台未启用');
        $user = User::find((int) $input['id']);
        if (!$user) abort(500, __('The user does not exist'));
        // A browser cookie must not be exchanged for an exportable privileged identity.
        if (app(\App\Services\AdminBrowserSessionService::class)->usesCookie($request)
            && ($user->is_admin || $user->is_staff)) abort(403, 'Privileged browser sessions cannot be exported');
        $code = AuthService::quickLoginCodeForUser($user);
        return response(['data' => Helper::getQuickLoginUrl($code), 'expires_in' => 60])
            ->header('Cache-Control', 'no-store, private');
    }

    use QueryOperators;

    public function resetSecret(Request $request)
    {
        $input = $request->validate(['id' => 'required|integer|min:1|max:2147483647', 'mode' => 'sometimes|required|in:grace,immediate,revoke']);
        try {
            app(\App\Services\UserMutationService::class)->resetSecret($request, (int) $input['id']);
            return $this->success(['grace_expires_at' => app(\App\Services\SubscriptionGraceService::class)->expiresAt((int) $input['id'])]);
        } catch (\App\Exceptions\UserMutationException $exception) {
            return $this->fail([$exception->getCode(), $exception->getMessage()]);
        } catch (\App\Services\Plugin\InterceptResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('User secret reset failed', ['exception_type' => get_class($exception)]);
            return $this->fail([500, '重置失败']);
        }
    }

    // Apply filters and sorts to the query builder.
    private function applyFiltersAndSorts(Request $request, Builder|QueryBuilder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    // Apply filters to the query builder.
    private function applyFilters(Request $request, Builder|QueryBuilder $builder): void
    {
        $request->validate([
            'filter' => 'sometimes|array|max:20',
            'filter.*' => ['array', function ($attribute, $value, $fail) {
                $field = is_array($value) ? ($value['id'] ?? null) : null;
                if (is_string($field) && in_array(strtolower(last(explode('.', $field))), User::PASSWORD_ATTRIBUTES, true)) $fail('不能按密码字段筛选');
            }],
            'filter.*.id' => ['required', 'string', 'max:128', 'regex:/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?\z/'],
            'filter.*.logic' => 'sometimes|in:and,or,AND,OR',
            'filter.*.value' => ['present', function ($attribute, $value, $fail) {
                $values = is_array($value) ? $value : [$value];
                if (count($values) > 500) {
                    $fail('筛选列表最多包含500个值');
                    return;
                }
                foreach ($values as $item) {
                    if ((!is_null($item) && !is_scalar($item)) || (is_string($item) && mb_strlen($item) > 255)) {
                        $fail('筛选值必须是长度不超过255的文本、数字或它们组成的列表');
                        return;
                    }
                }
            }],
        ]);
        if (!$request->has('filter')) {
            return;
        }

        // Request OR filters must not escape constraints added by the query hook.
        $builder->where(function ($builder) use ($request) {
            collect($request->input('filter'))->each(function ($filter) use ($builder) {
                $field = $filter['id'];
                $value = $filter['value'];
                $logic = strtolower($filter['logic'] ?? 'and');

                if ($logic === 'or') {
                    $builder->orWhere(function ($query) use ($field, $value) {
                        $this->buildFilterQuery($query, $field, $value);
                    });
                } else {
                    $builder->where(function ($query) use ($field, $value) {
                        $this->buildFilterQuery($query, $field, $value);
                    });
                }
            });
        });
    }

    // Build one filter query condition.
    private function buildFilterQuery(Builder|QueryBuilder $query, string $field, mixed $value): void
    {
        // 处理关联查询
        if (str_contains($field, '.')) {
            if (!method_exists($query, 'whereHas')) {
                return;
            }
            [$relation, $relationField] = explode('.', $field);
            $query->whereHas($relation, function ($q) use ($relationField, $value) {
                if (is_array($value)) {
                    $q->whereIn($relationField, $value);
                } else if (is_string($value) && str_contains($value, ':')) {
                    [$operator, $filterValue] = explode(':', $value, 2);
                    $this->applyQueryCondition($q, $relationField, $operator, $filterValue);
                } else {
                    $q->where($relationField, 'like', "%{$value}%");
                }
            });
            return;
        }

        $queryField = match ($field) {
            'total_used' => DB::raw('(u + d)'),
            'group_ids' => 'group_id',
            default => $field,
        };

        // Computed fields and aliases must resolve identically for every operator.
        if (is_array($value)) {
            $query->whereIn($queryField, $value);
            return;
        }

        // 处理基于运算符的过滤
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($queryField, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // 转换数字字符串为适当的类型
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        $this->applyQueryCondition($query, $queryField, $operator, $filterValue);
    }

    // Apply sorting rules to the query builder.
    private function applySorting(Request $request, Builder|QueryBuilder $builder): void
    {
        $request->validate([
            'sort' => 'sometimes|array|max:10',
            'sort.*' => ['array', function ($attribute, $value, $fail) {
                $field = is_array($value) ? ($value['id'] ?? null) : null;
                if (is_string($field) && in_array(strtolower($field), User::PASSWORD_ATTRIBUTES, true)) $fail('不能按密码字段排序');
            }],
            'sort.*.id' => ['required', 'string', 'max:128', 'regex:/\A[a-zA-Z_][a-zA-Z0-9_]*\z/'],
            'sort.*.desc' => 'required|boolean',
        ]);
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    // Resolve bulk operation scope and normalize user_ids.
    private function resolveScope(Request $request): array
    {
        $scope = $request->input('scope');
        $userIds = $request->input('user_ids');

        $hasSelection = is_array($userIds) && count(array_filter($userIds, static fn($v) => is_numeric($v))) > 0;
        $hasFilter = $request->has('filter') && !empty($request->input('filter'));

        if (!in_array($scope, ['selected', 'filtered', 'all'], true)) {
            if ($hasSelection) {
                $scope = 'selected';
            } elseif ($hasFilter) {
                $scope = 'filtered';
            } else {
                $scope = 'all';
            }
        }

        $normalizedIds = [];
        if ($scope === 'selected') {
            $normalizedIds = is_array($userIds) ? $userIds : [];
            $normalizedIds = array_values(array_unique(array_map(static function ($v) {
                return is_numeric($v) ? (int) $v : null;
            }, $normalizedIds)));
            $normalizedIds = array_values(array_filter($normalizedIds, static fn($v) => is_int($v)));
        }

        return [
            'scope' => $scope,
            'user_ids' => $normalizedIds,
        ];
    }

    // Fetch paginated user list (filters + sorting).
    public function fetch(Request $request)
    {
        $request->validate([
            'current' => 'sometimes|integer|min:1|max:2147483647',
            'pageSize' => 'sometimes|integer|min:1|max:500',
        ]);
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $userModel = User::query()
            ->with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select((new User())->getTable() . '.*')
            ->selectRaw('(u + d) as total_used');

        $userModel = HookManager::filter('admin.user.fetch.query', $userModel, $request);

        $this->applyFiltersAndSorts($request, $userModel);

        $total = (clone $userModel)->toBase()->getCountForPagination();
        $rows = ($current - 1) * $pageSize < $total
            ? $userModel->orderBy('id', 'desc')->forPage($current, $pageSize)->get()
            : $userModel->getModel()->newCollection();
        $users = new LengthAwarePaginator($rows, $total, $pageSize, $current);

        $onlineKeys = $users->getCollection()->map(fn ($user) => 'ALIVE_IP_USER_' . $user->id)->all();
        $onlineStates = $onlineKeys ? Cache::many($onlineKeys) : [];
        $users->getCollection()->transform(function ($user) use ($onlineStates): array {
            return self::transformUserData($user, self::parseOnlineState($onlineStates['ALIVE_IP_USER_' . $user->id] ?? null));
        });

        return $this->paginate($users);
    }

    // Transform user fields for API response.
    public static function transformUserData(User $user, ?array $online = null): array
    {
        $model = $user;
        $user = $user->toArray();
        $user['balance'] = $user['balance'] / 100;
        $user['commission_balance'] = $user['commission_balance'] / 100;
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token'], (int) $user['id']);
        $online ??= self::getOnlineState((int) $user['id']);
        $user['online_count'] = $online['count'];
        $user['alive_ip'] = $online['count'];
        $user['ips'] = $online['ips'];
        return HookManager::filter('admin.user.transform', $user, $model);
    }

    private static function getOnlineState(int $userId): array
    {
        return self::parseOnlineState(Cache::get('ALIVE_IP_USER_' . $userId));
    }

    public static function getOnlineStates(array $userIds): array
    {
        return \App\Services\UserOnlineStateService::forUsers($userIds);
    }

    private static function parseOnlineState($state): array
    {
        return \App\Services\UserOnlineStateService::parse($state);
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|min:1|max:2147483647'
        ], [
            'id.required' => '用户ID不能为空'
        ]);
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        $user->load('invite_user:id,email');
        if ($user) {
            $online = self::getOnlineState((int) $user->id);
            $user->setAttribute('online_count', $online['count']);
            $user->setAttribute('alive_ip', $online['count']);
            $user->setAttribute('ips', $online['ips']);
        }
        $user = HookManager::filter('admin.user.detail', $user, $request);
        return $this->success($user);
    }

    public function update(UserUpdate $request)
    {
        try {
            app(\App\Services\UserMutationService::class)->update($request, $request->validated());
            return $this->success(true);
        } catch (\App\Exceptions\UserMutationException $exception) {
            return $this->fail([$exception->getCode(), $exception->getMessage()]);
        } catch (\App\Services\Plugin\InterceptResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('User update failed', ['exception_type' => get_class($exception)]);
            return $this->fail([500, '保存失败']);
        }
    }

    // Export users to CSV.
    public function dumpCSV(Request $request)
    {
        $request->validate(['scope' => 'sometimes|nullable|in:selected,filtered,all']);
        $requestedScope = $request->input('scope');
        if ($requestedScope === 'selected' || ($requestedScope === null && $request->has('user_ids'))) {
            $request->validate([
                'user_ids' => 'required|array|min:1|max:10000',
                'user_ids.*' => 'required|integer|min:1|max:2147483647',
            ]);
        } elseif ($requestedScope === 'filtered' || ($requestedScope === null && $request->has('filter'))) {
            $request->validate([
                'filter' => $requestedScope === 'filtered'
                    ? 'required|array|min:1|max:20'
                    : 'nullable|array|max:20',
            ]);
        }
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        // 优化查询：使用with预加载plan关系，避免N+1问题
        $query = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'asc')
            ->select([
                'id',
                'email',
                'balance',
                'commission_balance',
                'transfer_enable',
                'u',
                'd',
                'expired_at',
                'token',
                'plan_id'
            ]);

        if ($scope === 'selected') {
            $query->whereIn('id', $userIds);
        } elseif ($scope === 'filtered') {
            $query->where(function (Builder $filters) use ($request) {
                $this->applyFilters($request, $filters);
            });
        } // all: ignore filter/sort

        $maxId = (int) (clone $query)->reorder()->max('id');
        $query->where('id', '<=', $maxId)->reorder('id', 'asc');
        $filename = 'users_' . date('Y-m-d_His') . '.csv';

        return Csv::download($filename, function ($output) use ($query) {
            Csv::writeRow($output, [
                '邮箱',
                '余额',
                '推广佣金',
                '总流量',
                '剩余流量',
                '套餐到期时间',
                '订阅计划',
                '订阅地址'
            ]);

            // 分批处理数据以减少内存使用
            $query->chunkById(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    $row = [
                            Csv::text($user->email),
                            number_format($user->balance / 100, 2),
                            number_format($user->commission_balance / 100, 2),
                            Helper::trafficConvert($user->transfer_enable),
                            Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d)),
                            $user->expired_at === null ? '长期有效' : date('Y-m-d H:i:s', $user->expired_at),
                            Csv::text($user->plan ? $user->plan->name : '无订阅'),
                            Csv::text(Helper::getSubscribeUrl($user->token, (int) $user->id))
                    ];
                    Csv::writeRow($output, $row);
                }

            });
        });
    }

    public function generate(UserGenerate $request)
    {
        try {
            $input = $request->validated();
            if (!empty($input['async'])) {
                $task = app(\App\Services\UserGenerationTaskService::class)->start((int) ($request->user['id'] ?? 0), $input);
                return $this->success($task);
            }
            $generator = app(\App\Services\UserGenerationService::class);
            $result = $generator->execute($generator->specification($input));
            if (!$result['bulk']) return $this->success(true);
            if (!empty($input['download_csv'])) return $generator->csv($result['data']);
            return response()->json(['code' => 0, 'message' => '批量生成成功', 'data' => $result['data']]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Log::warning('User generation failed', ['exception_type' => get_class($exception)]);
            return $this->fail([500, '生成失败']);
        }
    }

    public function generationStatus(Request $request)
    {
        $input = $request->validate(['batch_id' => 'nullable|string|max:128']);
        return $this->success(app(\App\Services\UserGenerationTaskService::class)->status((int) ($request->user['id'] ?? 0), $input['batch_id'] ?? null));
    }

    public function generationDownload(Request $request)
    {
        $input = $request->validate(['batch_id' => 'required|string|max:128']);
        return app(\App\Services\UserGenerationTaskService::class)->download((int) ($request->user['id'] ?? 0), $input['batch_id']);
    }

    public function sendMail(UserSendMail $request)
    {
        $request->validate(['scope' => 'sometimes|nullable|in:selected,filtered,all']);
        $requestedScope = $request->input('scope');
        if ($requestedScope === 'selected' || ($requestedScope === null && $request->has('user_ids'))) {
            $request->validate([
                'user_ids' => 'required|array|min:1|max:10000',
                'user_ids.*' => 'required|integer|min:1|max:2147483647',
            ]);
        }
        if ($requestedScope === 'filtered' || ($requestedScope === null && $request->has('filter'))) {
            $request->validate([
                'filter' => $requestedScope === 'filtered' ? 'required|array|min:1|max:20' : 'nullable|array|max:20',
                'filter.*.id' => 'required|string|max:64',
                'filter.*.logic' => 'sometimes|in:and,or',
                'filter.*.value' => ['required', function ($attribute, $value, $fail) {
                    $values = is_array($value) ? $value : [$value];
                    if (count($values) > 1000) { $fail('筛选值过多'); return; }
                    foreach ($values as $item) {
                        if (!is_scalar($item) || is_bool($item) || (is_string($item) && strlen($item) > 500)) {
                            $fail('筛选值格式错误或过长'); return;
                        }
                    }
                }],
            ]);
        }
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $builder = User::query()
            ->with('plan:id,name')
            ->select(['id', 'email', 'uuid', 'plan_id', 'expired_at', 'transfer_enable', 'u', 'd'])
            ->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            $builder->where(function (Builder $filters) use ($request) {
                $this->applyFilters($request, $filters);
            });
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        $subject = $request->input('subject');
        $content = $request->input('content');
        $appName = admin_setting('app_name', 'PanelCore');
        $appUrl = admin_setting('app_url');

        $chunkSize = 1000;
        $lastId = null;
        $queued = 0;

        // Keep descending delivery order without offsets that shift when users change.
        try {
            $maxId = (int) (clone $builder)->reorder()->max('id');
            $builder->where('id', '<=', $maxId);
            do {
                $users = (clone $builder)->forPageBeforeId($chunkSize, $lastId)->get();
                foreach ($users as $user) {
                    $vars = [
                        'app.name' => $appName,
                        'app.url' => $appUrl,
                        'now' => now()->format('Y-m-d H:i:s'),
                        'user.id' => $user->id,
                        'user.email' => $user->email,
                        'user.uuid' => $user->uuid,
                        'user.plan_name' => $user->plan?->name ?? '',
                        'user.expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '',
                        'user.transfer_enable' => (int) ($user->transfer_enable ?? 0),
                        'user.transfer_used' => (int) (($user->u ?? 0) + ($user->d ?? 0)),
                        'user.transfer_left' => (int) (($user->transfer_enable ?? 0) - (($user->u ?? 0) + ($user->d ?? 0))),
                    ];

                    $templateValue = [
                        'name' => $appName,
                        'url' => $appUrl,
                        'content' => $content,
                        'vars' => $vars,
                        'content_mode' => 'text',
                    ];

                    dispatch(new SendEmailJob([
                        'email' => $user->email,
                        'subject' => $subject,
                        'template_name' => 'notify',
                        'template_value' => $templateValue,
                    ], 'send_email_mass'));
                    $queued++;
                }
                $lastId = $users->last()?->id;
                $count = $users->count();
                unset($users);
            } while ($count === $chunkSize);
        } catch (\Throwable $exception) {
            Log::warning('Bulk mail enqueue incomplete', ['exception_type' => get_class($exception), 'queued_count' => $queued]);
            return $this->fail([500, $queued > 0
                ? "至少 {$queued} 封邮件已加入队列，后续入队未完成。请检查队列，不要直接重发整批。"
                : '邮件入队未完成，请检查队列后再决定是否重试。'], ['queued_count' => $queued]);
        }

        return $this->success(true);
    }

    public function ban(Request $request)
    {
        $request->validate([
            'scope' => 'required|in:selected,filtered,all',
        ]);
        if ($request->input('scope') === 'selected') $request->validate([
            'user_ids' => 'required|array|min:1|max:10000',
            'user_ids.*' => 'required|integer|min:1',
        ]);
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $builder = User::query()->select('id');

        if ($scope === 'filtered') {
            $request->validate([
                'filter' => 'required|array|min:1|max:20',
                'filter.*.id' => 'required|string|max:64',
                'filter.*.value' => ['required', function ($attribute, $value, $fail) {
                    $values = is_array($value) ? $value : [$value];
                    if (count($values) > 1000) { $fail('筛选值过多'); return; }
                    foreach ($values as $item) {
                        if (!is_scalar($item) || (is_string($item) && strlen($item) > 500)) {
                            $fail('筛选值格式错误或过长'); return;
                        }
                    }
                }],
                'filter.*.logic' => 'sometimes|in:and,or',
            ]);
            $builder->where(function ($query) use ($request) { $this->applyFilters($request, $query); });
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        $processed = 0;
        try {
            $ids = [];
            $maxId = (int)(clone $builder)->max('id');
            foreach ($builder->where('id', '<=', $maxId)->lazyById(200) as $user) {
                $ids[] = (int)$user->id;
                if (count($ids) === 200) {
                    $this->banUsersBatch($ids);
                    $processed += count($ids);
                    $ids = [];
                }
            }
            if (!empty($ids)) { $this->banUsersBatch($ids); $processed += count($ids); }
        } catch (\Throwable $e) {
            Log::error('User ban batch failed', ['error_type' => get_class($e)]);
            return $this->fail([500, $processed > 0 ? '部分用户已封禁，请刷新列表后重试' : '处理失败'], ['processed_count' => $processed]);
        }
        return $this->success(true);
    }

    private function banUsersBatch(array $ids): void
    {
        AuthService::revokeUsers($ids, function ($revoke) use ($ids) {
            return DB::transaction(function () use ($ids, $revoke) {
                User::whereIn('id', $ids)->update(['banned' => 1]);
                return $revoke();
            }, 3);
        });
    }

    public function setInviteUser(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:App\\Models\\User,id',
            'invite_user_email' => 'nullable|email:strict',
        ]);

        try {
            app(\App\Services\UserMutationService::class)->setInviter((int) $params['id'], $params['invite_user_email'] ?? null);
        } catch (\App\Exceptions\UserMutationException $exception) {
            return $this->fail([$exception->getCode(), $exception->getMessage()]);
        } catch (\Throwable $exception) {
            Log::warning('User inviter update failed', ['exception_type' => get_class($exception)]);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    // Delete user and related data.
    public function destroy(Request $request)
    {
        $input = $request->validate([
            'id' => 'required|integer|min:1|max:2147483647|exists:App\Models\User,id'
        ], [
            'id.required' => '用户ID不能为空',
            'id.exists' => '用户不存在'
        ]);
        try {
            app(\App\Services\UserDeletionService::class)->delete($request, (int) $input['id']);
            return $this->success(true);
        } catch (\App\Exceptions\UserMutationException $exception) {
            return $this->fail([$exception->getCode(), $exception->getMessage()]);
        } catch (\App\Services\Plugin\InterceptResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('User deletion failed', ['exception_type' => get_class($exception)]);
            return $this->fail([500, '删除失败']);
        }
    }
}
