<?php

namespace App\Console\Commands;

use App\Models\StatServer;
use App\Models\StatUser;
use App\Services\StatisticalService;
use Illuminate\Console\Command;
use App\Models\Stat;
use Illuminate\Support\Facades\DB;

class V2boardStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:statistics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '统计任务';

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
        $startAt = microtime(true);
        if (!$this->stat()) {
            return self::FAILURE;
        }
        info(sprintf('统计任务执行完毕。耗时:%.3f 秒', microtime(true) - $startAt));
        return self::SUCCESS;
    }

    private function statServer()
    {
        try {
            DB::beginTransaction();
            $createdAt = time();
            $recordAt = strtotime('-1 day', strtotime(date('Y-m-d')));
            $statService = new StatisticalService();
            $statService->setStartAt($recordAt);
            $statService->setServerStats();
            $stats = $statService->getStatServer();
            foreach ($stats as $stat) {
                if (!StatServer::insert([
                    'server_id' => $stat['server_id'],
                    'server_type' => $stat['server_type'],
                    'u' => $stat['u'],
                    'd' => $stat['d'],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'record_type' => 'd',
                    'record_at' => $recordAt
                ])) {
                    throw new \Exception('stat server fail');
                }
            }
            DB::commit();
            $statService->clearStatServer();
        } catch (\Exception $e) {
            DB::rollback();
            \Log::error($e->getMessage(), ['exception' => $e]);
        }
    }

    private function statUser()
    {
        try {
            DB::beginTransaction();
            $createdAt = time();
            $recordAt = strtotime('-1 day', strtotime(date('Y-m-d')));
            $statService = new StatisticalService();
            $statService->setStartAt($recordAt);
            $statService->setUserStats();
            $stats = $statService->getStatUser();
            foreach ($stats as $stat) {
                if (!StatUser::insert([
                    'user_id' => $stat['user_id'],
                    'u' => $stat['u'],
                    'd' => $stat['d'],
                    'server_rate' => $stat['server_rate'],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'record_type' => 'd',
                    'record_at' => $recordAt
                ])) {
                    throw new \Exception('stat user fail');
                }
            }
            DB::commit();
            $statService->clearStatUser();
        } catch (\Exception $e) {
            DB::rollback();
            \Log::error($e->getMessage(), ['exception' => $e]);
        }
    }

    private function stat(): bool
    {
        try {
            $endAt = strtotime(date('Y-m-d'));
            $startAt = strtotime('-1 day', $endAt);
            $statisticalService = app(StatisticalService::class);
            $statisticalService->setStartAt($startAt);
            $statisticalService->setEndAt($endAt);
            $data = $statisticalService->generateStatData();
            $data['record_at'] = $startAt;
            $data['record_type'] = 'd';
            $statistic = Stat::where('record_at', $startAt)
                ->where('record_type', 'd')
                ->first();
            if ($statistic) {
                if (!$statistic->update($data)) {
                    throw new \RuntimeException('Daily statistics update failed.');
                }
                return true;
            }
            $statistic = new Stat($data);
            if (!$statistic->save()) {
                throw new \RuntimeException('Daily statistics insert failed.');
            }
            return true;
        } catch (\Throwable $e) {
            \Log::error($e->getMessage(), ['exception' => $e]);
            $this->error('统计任务失败，请检查日志。');
            return false;
        }
    }
}
