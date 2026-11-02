<?php

namespace App\Jobs;

use App\Services\UserGenerationTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateUsersJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $batchId;
    public $tries = 1;
    public $timeout = 240;

    public function __construct(string $batchId)
    {
        $this->batchId = $batchId;
        $this->onConnection('user_generation'); $this->onQueue('user_generation');
    }

    public function handle(UserGenerationTaskService $tasks): void { $tasks->run($this->batchId); }
    public function failed(\Throwable $exception): void { app(UserGenerationTaskService::class)->failure($this->batchId); }
}
