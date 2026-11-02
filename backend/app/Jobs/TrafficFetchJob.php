<?php

namespace App\Jobs;

use App\Services\StatisticalService;
use App\Support\TrafficReport;
use App\Support\ServerRateSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $receivedAt = 0;

    public $tries = 3;
    public $timeout = 10;
    private const WRITE_BATCH_SIZE = 100;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->receivedAt = time();
        $rate = ServerRateSchedule::resolve($server, $this->receivedAt);
        $this->data = TrafficReport::normalize($data, $rate);
        // Do not replicate node secrets and unused configuration into Redis or failed-job payloads.
        $this->server = [
            'id' => (int) ($server['id'] ?? 0),
            'rate' => $rate,
        ];
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Validate the entire payload before any writes, including jobs queued by older versions.
        $rate = $this->server['rate'] ?? 1;
        $this->data = TrafficReport::normalize($this->data, $rate);
        $serverId = (int) ($this->server['id'] ?? 0);
        $serverType = strtolower((string) $this->protocol);
        // Keep delayed execution and retries in the original reporting period.
        // Jobs already queued by older versions have no receive timestamp.
        $receivedAt = $this->receivedAt ?: time();
        $recordAt = strtotime(date('Y-m-d', $receivedAt));
        $hourRecordAt = strtotime(date('Y-m-d H:00:00', $receivedAt));
        $minuteRecordAt = strtotime(date('Y-m-d H:i:00', $receivedAt));
        $statService = app(StatisticalService::class);
        $statService->setStartAt($recordAt);
        $serverUpload = 0;
        $serverDownload = 0;

        $batch = [];
        $writeBatch = function () use ($statService, &$batch, $rate, $serverId, $serverType, $recordAt, $hourRecordAt, $minuteRecordAt): void {
            $statService->withTrafficPipeline(function () use ($statService, &$batch, $rate, $serverId, $serverType, $recordAt, $hourRecordAt, $minuteRecordAt): void {
                foreach ($batch as [$userId, $upload, $download]) {
                    $statService->statUserTraffic($rate, $userId, $upload, $download);
                    if ($serverId > 0 && $serverType !== '') {
                        $statService->statUserServer('v2_stat_user_server', $recordAt, (int) $userId, $serverId, $serverType, $rate, $upload, $download);
                        $statService->statUserServer('v2_stat_user_server_hour', $hourRecordAt, (int) $userId, $serverId, $serverType, $rate, $upload, $download);
                        $statService->statUserServer('v2_stat_user_server_minute', $minuteRecordAt, (int) $userId, $serverId, $serverType, $rate, $upload, $download);
                    }
                }
            });
            $batch = [];
        };

        foreach ($this->data as $userId => $traffic) {
            $upload = (int) ($traffic[0] ?? 0);
            $download = (int) ($traffic[1] ?? 0);
            if ($upload <= 0 && $download <= 0) {
                continue;
            }

            $batch[] = [$userId, $upload, $download];
            $serverUpload += $upload;
            $serverDownload += $download;
            if (count($batch) === self::WRITE_BATCH_SIZE) {
                $writeBatch();
            }
        }
        if ($batch) {
            $writeBatch();
        }

        if ($serverId > 0 && $serverType !== '' && ($serverUpload > 0 || $serverDownload > 0)) {
            $statService->withTrafficPipeline(static function () use ($statService, $serverId, $serverType, $serverUpload, $serverDownload): void {
                $statService->statServer($serverId, $serverType, $serverUpload, $serverDownload);
            });
        }
    }
}
