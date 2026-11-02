<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Log as LogModel;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use App\Helpers\ResponseEnum;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;

class SystemController extends Controller
{
    private const DASHBOARD_STATUS_CACHE_SECONDS = 5;

    public function getSystemStatus()
    {
        $data = Cache::remember(
            'admin:dashboard:system-status:v2',
            self::DASHBOARD_STATUS_CACHE_SECONDS,
            fn () => [
                'schedule' => $this->getScheduleStatus(),
                'horizon' => $this->getHorizonStatus(),
                'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
                'traffic_accounting' => app(\App\Services\TrafficInboxService::class)->status(),
            ]
        );

        return $this->success($data);
    }

    public function getQueueWorkload()
    {
        try {
            $data = Cache::remember(
                'admin:dashboard:queue-workload:v1',
                self::DASHBOARD_STATUS_CACHE_SECONDS,
                fn () => collect(app(WorkloadRepository::class)->get())
                    ->sortBy('name')
                    ->values()
                    ->toArray()
            );
        } catch (\Throwable $e) {
            \Log::warning('队列工作负载读取失败', ['exception_type' => get_class($e), 'code' => $e->getCode(), 'exception' => $e]);
            return $this->fail([503, '队列工作负载暂不可用，请稍后重试']);
        }

        return $this->success($data);
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        try {
            return $this->getHorizonSummary()['status'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getHorizonSummary(): array
    {
        $masters = collect(app(MasterSupervisorRepository::class)->all());
        $paused = $masters->filter(static fn ($master) => $master->status === 'paused')->count();
        return ['pausedMasters' => $paused, 'status' => $masters->isNotEmpty() && $paused === 0];
    }

    public function getQueueStats()
    {
        try {
            $data = Cache::remember(
                'admin:dashboard:queue-stats:v1',
                self::DASHBOARD_STATUS_CACHE_SECONDS,
                function () {
                    try {
                        $failedJobs = app(JobRepository::class)->countRecentlyFailed();
                        $jobsPerMinute = app(MetricsRepository::class)->jobsProcessedPerMinute();
                        $horizon = $this->getHorizonSummary();
                        return [
                            'failedJobs' => $failedJobs,
                            'jobsPerMinute' => $jobsPerMinute,
                            'pausedMasters' => $horizon['pausedMasters'],
                            'periods' => [
                                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                                'recentJobs' => config('horizon.trim.recent'),
                            ],
                            'processes' => $this->totalProcessCount(),
                            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
                            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
                            'recentJobs' => app(JobRepository::class)->countRecent(),
                            'status' => $horizon['status'],
                            'wait' => collect(app(WaitTimeCalculator::class)->calculate()),
                        ];
                    } catch (\Throwable $e) {
                        return $this->unavailableQueueStats($e);
                    }
                }
            );
        } catch (\Throwable $e) {
            $data = $this->unavailableQueueStats($e);
        }

        return $this->success($data);
    }

    private function unavailableQueueStats(\Throwable $error): array
    {
        \Log::warning('队列统计读取失败', [
            'exception_type' => get_class($error), 'code' => $error->getCode(), 'exception' => $error,
        ]);
        return $this->emptyQueueStats() + [
            'unavailable' => true,
            'message' => '队列统计暂不可用，请检查服务日志',
        ];
    }

    private function emptyQueueStats(): array
    {
        return [
            'failedJobs' => 0,
            'jobsPerMinute' => 0,
            'pausedMasters' => 0,
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => 0,
            'queueWithMaxRuntime' => null,
            'queueWithMaxThroughput' => null,
            'recentJobs' => 0,
            'status' => false,
            'wait' => collect(),
        ];
    }

    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    protected function totalPausedMasters()
    {
        return $this->getHorizonSummary()['pausedMasters'];
    }

    public function getAuditLog(Request $request)
    {
        $request->validate([
            'id' => 'sometimes|integer|min:1',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'summary' => 'sometimes|boolean',
            'level' => 'sometimes|nullable|string|max:32',
            'action' => 'sometimes|nullable|string|max:16',
            'keyword' => 'sometimes|nullable|string|max:255',
        ]);
        if ($request->has('id')) {
            $log = LogModel::find((int) $request->input('id'));
            if (!$log) return $this->fail([404, '日志不存在或已被清理']);
            return response(['data' => $this->auditLogData($log, false)]);
        }
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('page_size', 10);
        $summary = $request->boolean('summary');
        $keyword = $request->input('keyword');

        $builder = LogModel::orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->when($request->input('level'), fn($q, $v) => $q->where('level', $v))
            ->when($request->input('action'), fn($q, $v) => $q->where('method', $v))
            ->when($keyword !== null && $keyword !== '', function ($q) use ($keyword) {
                $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
                $q->where(function ($q) use ($pattern) {
                    $q->whereRaw("uri LIKE ? ESCAPE '!'", [$pattern])
                      ->orWhereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                      ->orWhereRaw("data LIKE ? ESCAPE '!'", [$pattern])
                      ->orWhereRaw("context LIKE ? ESCAPE '!'", [$pattern]);
                });
            });

        $total = $builder->count();
        if (($current - 1) * $pageSize >= $total) {
            return response(['data' => [], 'total' => $total]);
        }
        if ($summary) {
            // Large request/exception bodies are fetched only when a detail is opened.
            $builder->select(['id', 'level', 'host', 'method', 'ip', 'created_at', 'updated_at'])
                ->selectRaw('SUBSTR(title, 1, 512) as title, SUBSTR(uri, 1, 512) as uri');
        }
        $res = $builder->forPage($current, $pageSize)
            ->get()
            ->map(fn ($item) => $this->auditLogData($item, $summary))
            ->values();

        return response(['data' => $res, 'total' => $total]);
    }

    private function auditLogData(LogModel $item, bool $summary): array
    {
        $data = [
            'id' => $item->id, 'title' => $item->title, 'level' => $item->level,
            'host' => $item->host, 'uri' => $item->uri, 'method' => $item->method,
            'ip' => $item->ip, 'created_at' => $item->created_at, 'updated_at' => $item->updated_at,
        ];
        if (!$summary) {
            $data['data'] = $item->data;
            $data['context'] = $item->context;
        }
        return app(\App\Logging\MysqlLoggerHandler::class)->sanitizeStoredLog($data);
    }

    public function getHorizonFailedJobs(Request $request)
    {
        return $this->horizonJobs($request, 'failed');
    }

    public function getHorizonJobs(Request $request)
    {
        $request->validate(['type' => ['sometimes', 'in:failed,recent,pending']]);
        return $this->horizonJobs($request, $request->input('type', 'failed'), true);
    }

    private function horizonJobs(Request $request, string $type, bool $brief = false)
    {
        $request->validate([
            'id' => ['sometimes', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'current' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('page_size', 10);
        $repository = app(JobRepository::class);
        $details = app(\App\Services\QueueJobDetails::class);
        if ($request->has('id')) {
            $job = $repository->getJobs([$request->input('id')])->first();
            if (!$job || empty($job->id) || empty($job->name)) {
                return $this->fail([404, '任务不存在或已超过保留时间']);
            }
            return response()->json(['data' => $details->detail($job)]);
        }
        $countMethod = ['failed' => 'countFailed', 'recent' => 'countRecent', 'pending' => 'countPending'][$type];
        $getMethod = ['failed' => 'getFailed', 'recent' => 'getRecent', 'pending' => 'getPending'][$type];
        $total = (int) $repository->$countMethod();
        $offset = ($current - 1) * $pageSize;
        // Horizon reads a bounded batch of 50 after the supplied index.
        $jobs = $offset < $total ? $repository->$getMethod($offset - 1)->take($pageSize) : collect();

        return response()->json([
            'data' => $jobs->map(fn ($job) => $details->summary($job, $brief))->values(),
            'total' => $total,
            'current' => $current,
            'page_size' => $pageSize,
        ]);
    }

}
