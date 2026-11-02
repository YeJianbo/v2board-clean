<?php

namespace App\Console\Commands;

use App\Models\Log;
use Illuminate\Console\Command;

class ResetLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '分批清理过期日志';

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
    public function handle(): int
    {
        $cutoff = strtotime('-1 month', time());
        $deleted = 0;
        Log::query()->where('created_at', '<', $cutoff)->select('id')
            ->chunkById(1000, static function ($logs) use ($cutoff, &$deleted) {
                // Recheck expiry in case a selected row changed before deletion.
                $deleted += Log::query()->whereIn('id', $logs->pluck('id'))
                    ->where('created_at', '<', $cutoff)->delete();
            });
        $this->info("Removed {$deleted} expired log rows");
        return 0;
    }
}
