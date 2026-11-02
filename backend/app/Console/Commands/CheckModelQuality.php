<?php

namespace App\Console\Commands;

use App\Services\ModelQualityService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckModelQuality extends Command
{
    protected $signature = 'model-relay:quality';
    protected $description = 'Process manually queued and explicitly enabled scheduled model quality tests';
    public function handle(ModelQualityService $service): int
    {
        if (!Schema::hasTable('v2_model_quality_run')) return 0;
        $staleBefore = Carbon::now('UTC')->subMinutes(15);
        DB::table('v2_model_quality_run')->where('status', 'running')->where('updated_at', '<', $staleBefore)
            ->select('id')->chunkById(100, function ($runs) use ($service, $staleBefore) {
                foreach ($runs as $run) $service->failRun((int) $run->id, $staleBefore);
            });
        foreach (DB::table('v2_model_quality_config')->where('next_run_at', '<=', Carbon::now('UTC'))->limit(20)->get(['upstream_id', 'settings', 'next_run_at']) as $config) {
            $s = json_decode($config->settings, true);
            try { if ($s['enabled']) $service->enqueue($config->upstream_id, 'scheduled'); } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Model quality enqueue failed', ['upstream_id' => $config->upstream_id, 'exception' => $e]);
                $this->warn('Skipped unavailable upstream #' . $config->upstream_id);
            }
            // A settings save after selection owns its new schedule.
            DB::table('v2_model_quality_config')->where('upstream_id', $config->upstream_id)
                ->where('settings', $config->settings)->where('next_run_at', $config->next_run_at)
                ->update(['next_run_at' => $s['enabled'] ? Carbon::now('UTC')->addMinutes($s['interval_minutes']) : null]);
        }
        foreach (DB::table('v2_model_quality_run')->where('status', 'queued')->orderBy('id')->limit(2)->pluck('id') as $id) {
            try { $service->execute($id); } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Model quality execution failed', ['run_id' => $id, 'exception' => $e]);
                $this->warn('Quality run #' . $id . ' failed');
            }
        }
        $service->pruneRuns();
        return 0;
    }
}
