<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use Illuminate\Console\Command;

class CheckTicket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:ticket';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '工单检查任务';

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
        $eligible = Ticket::where('status', Ticket::STATUS_OPEN)
            ->where('updated_at', '<=', time() - 24 * 3600)
            ->where('reply_status', Ticket::REPLY_REPLIED);

        (clone $eligible)->select('id')->chunkById(500, static function ($tickets) use ($eligible) {
            // Recheck current state in the write so a reply after selection is not closed.
            (clone $eligible)->whereKey($tickets->modelKeys())->update([
                'status' => Ticket::STATUS_CLOSED,
            ]);
        });
    }
}
