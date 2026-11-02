<?php

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SendRemindMail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:remindMail';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '发送提醒邮件';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $mailService = app(MailService::class);
        $now = time();
        $failed = 0;
        User::query()
            ->select(['id', 'email', 'expired_at', 'remind_expire', 'remind_traffic', 'u', 'd', 'transfer_enable'])
            ->where(static function ($query) use ($now) {
                $query->where(static function ($expire) use ($now) {
                    $expire->where('remind_expire', 1)
                        ->where('expired_at', '>', $now)
                        ->where('expired_at', '<', $now + 86400);
                })->orWhere(static function ($traffic) use ($now) {
                    $traffic->where('remind_traffic', 1)->where(static function ($active) use ($now) {
                        $active->whereNull('expired_at')->orWhere('expired_at', '>=', $now);
                    });
                });
            })
            ->chunkById(200, static function ($users) use ($mailService, &$failed) {
                foreach ($users as $user) {
                    foreach (['remind_expire' => 'remindExpire', 'remind_traffic' => 'remindTraffic'] as $setting => $method) {
                        if (!$user->{$setting}) continue;
                        if ($setting === 'remind_traffic' && $user->expired_at !== null && $user->expired_at < time()) continue;
                        try {
                            $mailService->{$method}($user);
                        } catch (\Throwable $error) {
                            // A failed queue/cache operation must not skip the remaining recipients.
                            $failed++;
                            Log::warning('Reminder could not be queued', [
                                'user_id' => (int) $user->id, 'type' => $method, 'exception' => get_class($error),
                            ]);
                        }
                    }
                }
            });
        return $failed ? 1 : 0;
    }
}
