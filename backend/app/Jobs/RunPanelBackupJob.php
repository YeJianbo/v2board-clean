<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class RunPanelBackupJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;
    public $uniqueFor = 3900;

    private string $type;

    public function __construct(string $type)
    {
        $this->type = $type;
        $this->onConnection('backup');
        $this->onQueue('panel_backup');
    }

    public function uniqueId(): string
    {
        // Database and migration backups already share one filesystem lock.
        return 'panel-backup';
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('panel:backup', [
            '--type' => $this->type,
            '--timeout' => max(1, (int) $this->timeout - 300),
        ]);
        if ($exitCode !== 0) {
            throw new RuntimeException('Panel backup failed: ' . trim(substr(Artisan::output(), -8192)));
        }
    }
}
