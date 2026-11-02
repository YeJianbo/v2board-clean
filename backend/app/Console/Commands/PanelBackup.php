<?php

namespace App\Console\Commands;

use App\Services\PanelGoogleDriveService;
use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PanelBackup extends Command
{
    protected $signature = 'panel:backup
        {--type= : database or migration}
        {--remote= : rclone destination, for example gdrive:panelcore}
        {--keep-days= : local and remote retention days}
        {--timeout=0 : maximum runtime in seconds; 0 disables the limit}';

    protected $description = 'Back up the panel database or create a complete migration package';

    public function handle(): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($timeout === false) {
            $this->error('timeout must be a non-negative integer');
            return 2;
        }
        $keepDays = filter_var($this->option('keep-days') ?? admin_setting('backup_keep_days', 14), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($keepDays === false) {
            $this->error('keep-days must be an integer between 1 and 3650; no backup or cleanup was started');
            return 2;
        }
        $type = (string) ($this->option('type') ?: admin_setting('backup_type', 'database'));
        $remoteOption = $this->option('remote');
        $remote = (string) ($remoteOption ?? admin_setting('backup_remote', ''));
        $rcloneConfig = '';
        if ($remoteOption === null) {
            $storage = (string) admin_setting('backup_storage', '');
            if (!in_array($storage, ['local', 'google_drive', 'rclone'], true)) {
                $storage = trim($remote) !== '' ? 'rclone' : 'local';
            }

            if ($storage === 'local') {
                $remote = '';
            } elseif ($storage === 'google_drive') {
                $googleDrive = app(PanelGoogleDriveService::class);
                if (!$googleDrive->isAuthorized()) {
                    $this->error('Google Drive is selected but has not been authorized');
                    return 1;
                }
                $remote = $googleDrive->remoteDestination();
                $rcloneConfig = $googleDrive->configPath();
            }
        }
        $outputDir = trim((string) admin_setting('backup_local_path', ''));
        if ($outputDir === '') {
            $outputDir = storage_path('app/backups');
        }
        $password = (string) admin_setting('backup_password', '');

        if (!in_array($type, ['database', 'migration'], true)) {
            $this->error('backup_type must be database or migration');
            return 2;
        }
        if ($remote !== '' && $password === '') {
            $this->error('Remote backup requires a backup password so the uploaded archive is encrypted');
            return 2;
        }

        $script = base_path('deploy/backup/backup.sh');
        if (!is_file($script)) {
            $this->error('Backup script is missing: ' . $script);
            return 1;
        }

        $command = [
            'bash',
            $script,
            '--site-root',
            base_path(),
            '--type',
            $type,
            '--output-dir',
            $outputDir,
            '--keep-days',
            (string) $keepDays,
        ];
        if ($remote !== '') {
            $command[] = '--remote';
            $command[] = $remote;
        }
        if ($rcloneConfig !== '') {
            $command[] = '--rclone-config';
            $command[] = $rcloneConfig;
        }

        if ($timeout > 0) {
            $timeoutBin = (new ExecutableFinder())->find('timeout', null, ['/usr/bin', '/bin']);
            if (!$timeoutBin) {
                $this->error('GNU timeout is required for time-limited backups');
                return 2;
            }
            // Keep the entire shell pipeline in one timed process group.
            $command = array_merge([$timeoutBin, '--signal=TERM', '--kill-after=30s', $timeout . 's'], $command);
        }

        $process = new Process($command, base_path(), [
            'V2BOARD_BACKUP_PASSWORD' => $password,
        ]);
        $process->setTimeout($timeout > 0 ? (float) $timeout + 60 : null);
        // Artisan::call buffers console output too; avoid retaining the full stream twice.
        $process->disableOutput();
        $outputRemaining = 262144;
        $errorTail = '';
        $outputTruncated = false;
        $process->run(function ($type, $buffer) use (&$outputRemaining, &$errorTail, &$outputTruncated): void {
            if ($type === Process::ERR) {
                $errorTail = substr($errorTail . substr($buffer, -8192), -8192);
            }
            $length = strlen($buffer);
            $truncatedChunk = $length > $outputRemaining;
            if ($outputRemaining > 0) {
                $this->output->write(substr($buffer, 0, $outputRemaining));
                $outputRemaining = max(0, $outputRemaining - $length);
            }
            if ($truncatedChunk && !$outputTruncated) {
                $outputTruncated = true;
                $this->output->writeln("\nBackup output limit reached; remaining progress output is suppressed.");
            }
        });

        if (!$process->isSuccessful()) {
            if ($timeout > 0 && $process->getExitCode() === 124) {
                $this->error('Backup timed out after ' . $timeout . ' seconds');
                return 124;
            }
            $this->error(trim($errorTail) ?: 'Backup failed');
            return $process->getExitCode() ?: 1;
        }

        if ($outputTruncated) {
            $this->info('Backup completed successfully; see storage/app/backup/status.json for archive details');
        }
        return 0;
    }
}
