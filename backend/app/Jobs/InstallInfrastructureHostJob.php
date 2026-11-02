<?php

namespace App\Jobs;

use App\Services\InfrastructureMigrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InstallInfrastructureHostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 10000;
    public $tries = 1;
    public string $taskId;

    public function __construct(string $taskId)
    {
        $this->taskId = $taskId;
        $this->onConnection('infrastructure');
        $this->onQueue('infrastructure_install');
    }

    public function handle(InfrastructureMigrationService $service): void
    {
        $service->interrupted($this->taskId);
    }

    public function failed(\Throwable $error): void
    {
        app(InfrastructureMigrationService::class)->interrupted($this->taskId);
    }
}
