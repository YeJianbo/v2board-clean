<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clear:user {--execute : Delete eligible users during a maintenance window}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理用户';

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
        $references = [
            'v2_order' => ['user_id', 'invite_user_id'],
            'v2_ticket' => ['user_id'],
            'v2_ticket_message' => ['user_id'],
            'v2_commission_log' => ['user_id', 'invite_user_id'],
            'v2_invite_code' => ['user_id'],
            'v2_giftcard_usage' => ['user_id'],
            'v2_stat_user' => ['user_id'],
            'v2_stat_user_server' => ['user_id'],
            'v2_stat_user_server_hour' => ['user_id'],
            'v2_stat_user_server_minute' => ['user_id'],
        ];
        $builder = User::whereNull('plan_id')
            ->where('transfer_enable', 0)
            ->where('expired_at', 0)
            ->whereNull('last_login_at')
            ->whereNull('group_id')
            ->whereNull('invite_user_id')
            ->whereNull('telegram_id');
        foreach (['balance', 'commission_balance', 'u', 'd', 't', 'is_admin', 'is_staff', 'banned', 'auto_renewal'] as $field) {
            $builder->where($field, 0);
        }
        $builder->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('v2_user as invited')->whereColumn('invited.invite_user_id', 'v2_user.id');
        });
        foreach ($references as $table => $columns) {
            if (!Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                $builder->whereNotExists(function ($query) use ($table, $column) {
                    $query->selectRaw('1')->from($table)->whereColumn($table . '.' . $column, 'v2_user.id');
                });
            }
        }
        if (!$this->option('execute')) {
            $count = (clone $builder)->count();
            $this->info("预览：{$count}位用户符合清理条件，未删除任何用户。确认备份和维护窗口后使用 --execute 执行。");
            return 0;
        }
        $maximumId = (int) User::max('id');
        $cursor = 0;
        $deleted = 0;
        do {
            $ids = (clone $builder)->where('id', '>', $cursor)->where('id', '<=', $maximumId)
                ->orderBy('id')->limit(500)->pluck('id')->all();
            if (!$ids) break;
            $cursor = (int) end($ids);
            $deleted += DB::transaction(function () use ($builder, $ids) {
                $eligible = (clone $builder)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
                return $eligible->isEmpty() ? 0 : User::whereIn('id', $eligible)->delete();
            }, 3);
        } while (count($ids) === 500);
        $this->info("已删除{$deleted}位符合清理条件的用户");
        return 0;
    }
}
