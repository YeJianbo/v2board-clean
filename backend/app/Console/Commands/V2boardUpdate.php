<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class V2boardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update {--no-queue-restart : 不发送 Horizon 队列终止请求}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 更新';

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
        $stage = 'config:cache';
        try {
            $code = $this->runStage($stage);
            if ($code !== 0) {
                return $code;
            }

            $stage = '现代基础结构预检';
            $this->assertModernSchema();

            $stage = 'migrate';
            $code = $this->runStage($stage, ['--force' => true, '--no-interaction' => true]);
            if ($code !== 0) {
                return $code;
            }

            if (!$this->option('no-queue-restart')) {
                $stage = 'horizon:terminate';
                $code = $this->runStage($stage);
                if ($code !== 0) {
                    return $code;
                }
            }
        } catch (\Throwable $exception) {
            $this->error($stage . ' 失败：' . $exception->getMessage());
            return 1;
        }

        $this->info('更新完成，数据库迁移已成功执行。');
        if ($this->option('no-queue-restart')) {
            $this->info('已跳过队列终止请求，请由独立发布器或进程管理器管理队列重启。');
        } else {
            $this->info('已发送 Horizon 队列终止请求，请确保进程管理器会重新拉起队列。');
        }
        $this->info('本命令未重载 HTTP 常驻进程；如使用 Webman、Octane 等，请通过对应进程管理器重载。');
        return 0;
    }

    private function runStage(string $command, array $arguments = []): int
    {
        $code = $this->call($command, $arguments);
        if ($code !== 0) {
            $this->error($command . ' 失败，退出码：' . $code);
        }
        return $code;
    }

    private function assertModernSchema(): void
    {
        // Check base objects and historical conversions, not every installed feature column.
        $baseTables = [
            'v2_commission_log' => [],
            'v2_coupon' => [],
            // The giftcard migration places used_at after used_user_ids.
            'v2_giftcard' => ['used_user_ids'],
            'v2_invite_code' => [],
            'v2_knowledge' => [],
            'v2_log' => [],
            'v2_mail_log' => [],
            'v2_notice' => [],
            'v2_order' => ['period'],
            'v2_payment' => [],
            'v2_plan' => [],
            'v2_server_group' => [],
            'v2_server_tuic' => [],
            'v2_server_hysteria' => [],
            'v2_server_route' => [],
            'v2_server_shadowsocks' => [],
            'v2_server_trojan' => [],
            'v2_server_vless' => [],
            'v2_server_vmess' => ['networkSettings'],
            'v2_server_anytls' => [],
            'v2_server_v2node' => [],
            'v2_stat' => ['paid_count', 'paid_total', 'commission_total', 'order_total'],
            'v2_stat_server' => [],
            'v2_stat_user' => ['server_rate'],
            'v2_ticket' => [],
            'v2_ticket_message' => [],
            'v2_user' => ['uuid', 'banned'],
        ];
        $problems = [];
        foreach ($baseTables as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $problems[] = '缺少表 ' . $table;
                continue;
            }
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $problems[] = '缺少列 ' . $table . '.' . $column;
                }
            }
        }

        // Retained legacy tables/columns are harmless unless the active data shape needs conversion.
        if (Schema::hasTable('v2_stat_user')) {
            foreach (['server_id', 'server_type'] as $column) {
                if (Schema::hasColumn('v2_stat_user', $column)) {
                    $problems[] = 'v2_stat_user.' . $column . ' 表示旧版按节点统计形态，需专门聚合迁移';
                }
            }
        }

        if ($problems) {
            throw new \RuntimeException(
                implode('；', $problems) . '。未识别为可直接执行现有迁移的现代基础；请备份数据库后进行专门迁移。'
                . '不会执行历史 update.sql 或自动清空统计数据。'
            );
        }
    }
}
