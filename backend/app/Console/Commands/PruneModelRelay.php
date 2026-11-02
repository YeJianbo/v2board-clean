<?php

namespace App\Console\Commands;

use App\Services\ModelRelayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneModelRelay extends Command
{
    protected $signature = 'model-relay:maintain';
    protected $description = 'Settle interrupted relay reservations and retain 90 days of request details';
    public function handle(ModelRelayService $service): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_model_usage')) return 0;
        $failed = 0;
        $pending = DB::table('v2_model_usage')->where('status', 'pending')
            ->where('created_at', '<', \Carbon\Carbon::now('UTC')->subMinutes(20))
            ->orderBy('created_at')->orderBy('request_id')->limit(500)->pluck('request_id');
        foreach ($pending as $id) {
            try {
                $service->settle(['request_id' => $id, 'status' => 'unknown', 'http_status' => 0, 'duration_ms' => 1200000]);
            } catch (\Throwable $error) {
                $failed++;
                Log::warning('Model relay maintenance settlement failed', [
                    'request_id' => $id, 'exception' => $error,
                ]);
            }
        }
        $cutoff = \Carbon\Carbon::now('UTC')->subDays(90);
        $old = DB::table('v2_model_usage')->where('status', '!=', 'pending')->where('created_at', '<', $cutoff)
            ->orderBy('created_at')->orderBy('request_id')->limit(5000)->pluck('request_id');
        if ($old->isNotEmpty()) {
            DB::table('v2_model_usage')->whereIn('request_id', $old)
                ->where('status', '!=', 'pending')->where('created_at', '<', $cutoff)->delete();
        }
        return $failed ? 1 : 0;
    }
}
