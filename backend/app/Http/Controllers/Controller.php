<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\ServerMetadataService;
use App\Support\TrafficReport;
use App\Support\ServerRateSchedule;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Controller extends BaseController
{
    use DispatchesJobs, ValidatesRequests, ApiResponse;
    private array $serverRateSchedule = [];

    protected function validateServerSaveRequest(Request $request): ?array
    {
        $request->validate(['rate' => TrafficReport::nodeRateRules()]);
        $this->serverRateSchedule = ServerRateSchedule::fromRequest($request);
        return $this->validateServerMetadata($request);
    }

    protected function serverSaveParameters(array $params): array
    {
        unset($params['rate_time_enable'], $params['rate_time_ranges']);
        return array_merge($params, $this->serverRateSchedule);
    }

    protected function validateServerMetadata(Request $request): ?array
    {
        return app(ServerMetadataService::class)->validateRequest($request);
    }

    protected function saveServerMetadata(string $serverType, int $serverId, ?array $metadata): void
    {
        try {
            app(ServerMetadataService::class)->save($serverType, $serverId, $metadata);
        } finally {
            $this->invalidateServerCollection($serverType);
        }
    }

    protected function saveServerWithMetadata(Model $prototype, array $params, string $serverType, ?array $metadata, ?callable $afterSave = null): Model
    {
        $id = $prototype->exists ? $prototype->getKey() : null;
        return $prototype->getConnection()->transaction(function () use ($prototype, $id, $params, $serverType, $metadata, $afterSave) {
            // Retries must start with a fresh model, not a rolled-back insert's ID/original state.
            $server = $prototype->newInstance();
            if ($id !== null) $server = $server->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            $before = $afterSave === null ? null : clone $server;
            $server->fill($params);
            if (!$server->save() || !$server->exists || !$server->getKey()) {
                throw new \RuntimeException('Node save rejected.');
            }
            $this->saveServerMetadata($serverType, (int) $server->getKey(), $metadata);
            if ($afterSave !== null) $afterSave($before, $server);
            return $server;
        }, 3);
    }

    protected function deleteServerWithMetadata(Model $prototype, string $serverType): Model
    {
        return $prototype->getConnection()->transaction(function () use ($prototype, $serverType) {
            $server = $prototype->newQuery()->whereKey($prototype->getKey())->lockForUpdate()->firstOrFail();
            if (!$server->delete()) throw new \RuntimeException('Node delete rejected.');
            $this->deleteServerMetadata($serverType, (int) $server->getKey());
            return $server;
        }, 3);
    }

    protected function copyServerWithMetadata(Model $prototype, string $serverType, array $overrides, bool $preserveTimestamps = false): Model
    {
        return $prototype->getConnection()->transaction(function () use ($prototype, $serverType, $overrides, $preserveTimestamps) {
            $source = $prototype->newQuery()->whereKey($prototype->getKey())->lockForUpdate()->firstOrFail();
            $copy = $preserveTimestamps ? $source->newInstance($source->toArray()) : $source->replicate();
            $copy->fill($overrides);
            if (!$copy->save() || !$copy->exists || !$copy->getKey()) {
                throw new \RuntimeException('Node copy rejected.');
            }
            $this->copyServerMetadata($serverType, (int) $source->getKey(), (int) $copy->getKey());
            return $copy;
        }, 3);
    }

    protected function deleteServerMetadata(string $serverType, int $serverId): void
    {
        try {
            app(ServerMetadataService::class)->delete($serverType, $serverId);
        } finally {
            $this->invalidateServerCollection($serverType);
        }
    }

    protected function copyServerMetadata(string $serverType, int $sourceServerId, int $targetServerId): void
    {
        try {
            app(ServerMetadataService::class)->copy($serverType, $sourceServerId, $targetServerId);
        } finally {
            $this->invalidateServerCollection($serverType);
        }
    }

    protected function invalidateServerCollection(string $serverType): void
    {
        DB::afterCommit(static function () use ($serverType) {
            try {
                Cache::forget('servers_' . $serverType);
            } catch (\Throwable $exception) {
                Log::warning('节点订阅缓存清理失败', [
                    'server_type' => $serverType,
                    'exception_type' => get_class($exception),
                ]);
            }
        });
    }
}
