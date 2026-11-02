<?php

namespace App\Services;

use App\Models\InfrastructureBackup;
use App\Models\InfrastructureHost;
use App\Models\InfrastructureTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class InfrastructureMigrationService
{
    private $packageLock = null;
    private ?string $packagePath = null;

    public function backup(string $id): InfrastructureBackup
    {
        $backup = InfrastructureBackup::findOrFail($id);
        if ($backup->kind !== 'migration' || $backup->state !== 'ready' || !is_file($backup->path())) abort(409, '请选择已经传输完成的完整迁移备份');
        return $backup;
    }

    public function settings(InfrastructureBackup $backup, array $input): array
    {
        $source = InfrastructureHost::findOrFail($backup->host_id);
        return ['site_root' => $input['site_root'] ?? '/srv/panelcore', 'domain' => $input['domain'] ?? $source->settings['domain'],
            'database_name' => $input['database_name'] ?? 'panelcore', 'database_user' => $input['database_user'] ?? 'panelcore'];
    }

    public function package(InfrastructureBackup $backup, array $settings): string
    {
        $root = storage_path('app/infrastructure/migration');
        File::ensureDirectoryExists($root, 0700);
        $lock = fopen($root . '/.export.lock', 'c');
        if ($lock === false) throw new RuntimeException('无法锁定迁移导出');
        chmod($root . '/.export.lock', 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(429, '已有迁移数据正在打包或下载，请稍后重试', ['Retry-After' => '60']);
        }
        // Keep the disk lock through streaming; a cache lease could expire during a slow download.
        $this->packageLock = $lock;
        $directory = null;
        try {
            if (!hash_equals((string) $backup->sha256, hash_file('sha256', $backup->path()))) abort(409, '备份完整性核验失败');
            $directory = $root . '/' . Str::uuid();
            if (!mkdir($directory, 0700)) throw new RuntimeException('无法创建迁移导出目录');
            mkdir($directory . '/data', 0700);
            if (!copy($backup->path(), $directory . '/data/archive.enc')) throw new RuntimeException('无法复制迁移备份');
            $settings['package_id'] = (string) Str::uuid();
            if (file_put_contents($directory . '/data/settings.json', json_encode($settings, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('无法写入迁移配置');
            // Root executables and machine credentials are never distributed by the panel.
            $process = new Process(['tar', '-czf', $directory . '/migration.tar.gz', '-C', $directory . '/data', 'settings.json', 'archive.enc'], null, null, null, 600);
            $process->disableOutput();
            $process->run();
            if (!$process->isSuccessful()) throw new RuntimeException('迁移数据打包失败，退出码 ' . $process->getExitCode());
            chmod($directory . '/migration.tar.gz', 0600);
            File::deleteDirectory($directory . '/data');
            return $this->packagePath = $directory . '/migration.tar.gz';
        } catch (\Throwable $error) {
            try {
                if ($directory !== null) File::deleteDirectory($directory);
            } finally {
                $this->releasePackageLock();
            }
            throw $error;
        }
    }

    private function releasePackageLock(): void
    {
        if (is_resource($this->packageLock)) {
            flock($this->packageLock, LOCK_UN);
            fclose($this->packageLock);
        }
        $this->packageLock = null;
        $this->packagePath = null;
    }

    public function cleanup(string $path): void
    {
        $root = realpath(storage_path('app/infrastructure/migration'));
        $directory = realpath(dirname($path));
        try {
            if ($root && $directory && dirname($directory) === $root) File::deleteDirectory($directory);
        } finally {
            if ($path === $this->packagePath) $this->releasePackageLock();
        }
    }

    public function download(string $path, string $name)
    {
        return response()->streamDownload(function () use ($path) {
            $file = null;
            $ignoreAbort = ignore_user_abort(true);
            try {
                $file = fopen($path, 'rb');
                if ($file === false) throw new RuntimeException('无法读取迁移数据');
                fpassthru($file);
            } finally {
                if (is_resource($file)) fclose($file);
                try { $this->cleanup($path); } finally { ignore_user_abort((bool) $ignoreAbort); }
            }
        }, $name, ['Content-Type' => 'application/gzip', 'Content-Length' => filesize($path), 'Cache-Control' => 'no-store, private']);
    }

    public function ticket(InfrastructureBackup $backup, array $settings, string $origin): array
    {
        if (!preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?$~', $origin)) abort(422, '安装命令需要面板 HTTPS 地址');
        $token = Str::random(64);
        $expires = time() + 1800;
        Cache::put('migration-install:' . hash('sha256', $token), Crypt::encryptString(json_encode([
            'backup_id' => $backup->id, 'settings' => $settings, 'origin' => $origin, 'expires_at' => $expires,
        ], JSON_THROW_ON_ERROR)), 1800);
        return ['command' => 'panelcore-install --panel ' . escapeshellarg($origin), 'token' => $token, 'expires_at' => $expires];
    }

    public function ticketData(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9]{64}$/', $token)) abort(403, '安装授权无效或已过期');
        $value = Cache::get('migration-install:' . hash('sha256', $token));
        if (!$value) abort(403, '安装授权无效或已过期');
        $data = json_decode(Crypt::decryptString($value), true, 32, JSON_THROW_ON_ERROR);
        if ($data['expires_at'] <= time()) abort(403, '安装授权已过期');
        $this->backup($data['backup_id']);
        return $data;
    }

    public function interrupted(string $id): void
    {
        // Old queued SSH jobs must not reconnect after the security boundary changes.
        DB::transaction(function () use ($id) {
            $task = InfrastructureTask::query()->lockForUpdate()->find($id);
            if (!$task || $task->action !== 'migration.ssh') return;
            $payload = $task->payload();
            $payload['private_key'] = '';
            $task->encrypted_payload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            if (in_array($task->state, ['queued', 'dispatched'], true)) {
                $task->state = $task->state === 'queued' ? 'cancelled' : 'unknown';
                $task->result = ['message' => 'SSH 安装已停用；请使用独立可信安装器，已派发任务需检查目标状态'];
                $task->completed_at = time();
            }
            $task->save();
        });
    }
}
