<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunPanelBackupJob;
use App\Services\PanelGoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

class BackupController extends Controller
{
    private const LAST_JOB_KEY = 'panel:backup:last-job:v1';

    public function status(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $request->validate(['job_id' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/']]);
        $statusFile = storage_path('app/backup/status.json');
        $status = [];
        if (is_file($statusFile)) {
            $contents = file_get_contents($statusFile, false, null, 0, 16385);
            $decoded = is_string($contents) && strlen($contents) <= 16384 ? json_decode($contents, true) : null;
            $valid = is_array($decoded) && in_array($decoded['status'] ?? null, ['running', 'success', 'failed'], true);
            if ($valid) {
                foreach (['type', 'started_at', 'completed_at', 'file', 'remote', 'message'] as $field) {
                    if (array_key_exists($field, $decoded) && !is_string($decoded[$field])) {
                        $valid = false;
                        break;
                    }
                }
            }
            $status = $valid ? $decoded : ['status' => 'unknown', 'message' => '备份状态记录异常，请稍后重试'];
        }

        return $this->success([
            'enabled' => (bool) admin_setting('backup_enable', false),
            'rclone_available' => is_executable('/usr/bin/rclone') || is_executable('/usr/local/bin/rclone'),
            'status' => $status,
            'execution' => $this->executionStatus($request->input('job_id'), $status),
            'google_drive' => $googleDrive->status($request),
        ]);
    }

    public function run(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $params = $request->validate([
            'type' => 'nullable|in:database,migration',
        ]);
        $type = (string) ($params['type'] ?? admin_setting('backup_type', 'database'));
        if (admin_setting('backup_storage', '') === 'google_drive' && !$googleDrive->isAuthorized()) {
            throw ValidationException::withMessages([
                'storage' => ['Google Drive 尚未授权，不能启动远端备份'],
            ]);
        }
        $job = new RunPanelBackupJob($type);
        $cache = app(CacheRepository::class);
        if (!(new UniqueLock($cache))->acquire($job)) {
            return $this->fail([409, '已有备份请求待确认或正在执行，请刷新状态']);
        }
        $queuedAt = time();
        // Direct dispatch reuses the acquired unique lock; PendingDispatch would acquire twice.
        // A transport failure can happen after enqueueing, so retain the existing lock lease.
        $jobId = app(Dispatcher::class)->dispatch($job);
        try {
            Cache::put(self::LAST_JOB_KEY, ['id' => (string) $jobId, 'type' => $type, 'queued_at' => $queuedAt], $job->uniqueFor);
        } catch (Throwable $error) {
            // The job is already queued; do not report a failed submission or release its lock.
            Log::warning('Backup job queued, but latest-job reference could not be stored');
        }

        return $this->success([
            'queued' => true,
            'type' => $type,
            'job_id' => (string) $jobId,
            'queued_at' => $queuedAt,
        ]);
    }

    private function executionStatus(?string $requestedId, array $status): array
    {
        $unknown = ['phase' => 'unknown', 'message' => '暂时无法确认任务状态，请稍后刷新'];
        try {
            $latest = Cache::get(self::LAST_JOB_KEY);
            $latest = is_array($latest) ? $latest : [];
            $id = $requestedId ?: (string) ($latest['id'] ?? '');
            if ($id !== '') {
                $job = app(JobRepository::class)->getJobs([$id])->first();
                if ($job && $job->name === RunPanelBackupJob::class && $job->connection === 'backup' && $job->queue === 'panel_backup') {
                    $phase = ['pending' => 'queued', 'reserved' => 'running', 'completed' => 'success', 'failed' => 'failed'][$job->status] ?? 'unknown';
                    $finishedAt = (int) ($job->completed_at ?: $job->failed_at ?: 0);
                    $fileStartedAt = strtotime((string) ($status['started_at'] ?? '')) ?: 0;
                    if ($requestedId || !$finishedAt || $fileStartedAt <= $finishedAt) {
                        return ['phase' => $phase, 'job_id' => $id,
                            'type' => ($latest['id'] ?? '') === $id ? ($latest['type'] ?? '') : '',
                            'queued_at' => ($latest['id'] ?? '') === $id ? (int) ($latest['queued_at'] ?? 0) : 0,
                            'started_at' => (int) ($job->reserved_at ?: 0), 'completed_at' => $finishedAt,
                            'message' => $phase === 'failed' ? '备份执行失败，请查看队列失败记录' : ($phase === 'unknown' ? $unknown['message'] : '')];
                    }
                } elseif ($requestedId) {
                    return $unknown + ['job_id' => $id];
                }
            }
            $phase = (string) ($status['status'] ?? 'idle');
            return ['phase' => in_array($phase, ['running', 'success', 'failed', 'unknown'], true) ? $phase : 'idle',
                'type' => $status['type'] ?? '', 'started_at' => $status['started_at'] ?? '',
                'completed_at' => $status['completed_at'] ?? '', 'message' => $status['message'] ?? ''];
        } catch (Throwable $error) {
            return $unknown;
        }
    }

    public function googleStatus(Request $request, PanelGoogleDriveService $googleDrive)
    {
        return $this->success($googleDrive->status($request));
    }

    public function googleConfigure(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $params = $this->validateGoogleConfig($request);
        try {
            $googleDrive->configure(
                (string) $params['client_id'],
                (string) ($params['client_secret'] ?? ''),
                (string) $params['folder']
            );
        } catch (Throwable $error) {
            throw ValidationException::withMessages([
                'google_drive' => [$error->getMessage()],
            ]);
        }

        return $this->success($googleDrive->status($request));
    }

    public function googleAuthorize(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $params = $this->validateGoogleConfig($request);
        try {
            $googleDrive->configure(
                (string) $params['client_id'],
                (string) ($params['client_secret'] ?? ''),
                (string) $params['folder']
            );

            return $this->success($googleDrive->createAuthorizationUrl($request));
        } catch (Throwable $error) {
            throw ValidationException::withMessages([
                'google_drive' => [$error->getMessage()],
            ]);
        }
    }

    public function googleTest(Request $request, PanelGoogleDriveService $googleDrive)
    {
        try {
            return $this->success($googleDrive->testConnection());
        } catch (Throwable $error) {
            throw ValidationException::withMessages([
                'google_drive' => [$error->getMessage()],
            ]);
        }
    }

    public function googleDisconnect(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $googleDrive->disconnect();
        return $this->success($googleDrive->status($request));
    }

    public function googleCallback(Request $request, PanelGoogleDriveService $googleDrive)
    {
        $result = $googleDrive->completeAuthorization($request);
        $query = http_build_query([
            'google_drive' => $result['success'] ? 'authorized' : 'error',
        ]);
        $separator = strpos($result['return_url'], '?') === false ? '?' : '&';

        return redirect()->away($result['return_url'] . $separator . $query);
    }

    private function validateGoogleConfig(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'client_secret' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'folder' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ]);
    }

}
