<?php

namespace App\Jobs;

use App\Services\TelegramService;
use App\Exceptions\TelegramApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected $parseMode = 'markdown';

    public $tries = 3;
    public $timeout = 15;
    public $backoff = [5, 20];

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, string $parseMode = 'markdown')
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->parseMode = $parseMode;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(TelegramService $telegramService)
    {
        try {
            $telegramService->sendMessage($this->telegramId, $this->text, $this->parseMode);
        } catch (TelegramApiException $exception) {
            if (!$this->job) throw $exception;
            if ($exception->isPermanent()) {
                $this->fail($exception);
                return;
            }
            if ($exception->getCode() === 429) {
                $this->release(max(5, $exception->retryAfter));
                return;
            }
            throw $exception;
        }
    }
}
