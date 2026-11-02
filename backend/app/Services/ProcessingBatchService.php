<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessingBatchService
{
    private const REGISTRY_KEY = 'v2board:processing_batches';
    private const RECEIPT_LOOKUP_BATCH_SIZE = 500;

    public function move(string $sourceKey, string $kind, ?string $batchId = null): array
    {
        if (!in_array($kind, ['hash', 'zset'], true)) {
            throw new \InvalidArgumentException('Unsupported Redis batch type');
        }

        $redis = Redis::connection();
        if (!$redis->exists($sourceKey)) {
            return [null, []];
        }

        $batchId = $batchId ?: bin2hex(random_bytes(16));
        $processingKey = $sourceKey . ':processing:' . $batchId;
        $entry = [
            'batch_id' => $batchId,
            'source_key' => $sourceKey,
            'processing_key' => $processingKey,
            'kind' => $kind,
        ];
        $member = $this->encodeEntry($entry);
        $redis->sadd(self::REGISTRY_KEY, $member);

        // A lost reply does not prove RENAME failed. Keep the registration so recovery
        // can find the moved data, or remove an empty registration on the next pass.
        if ($redis->rename($sourceKey, $processingKey) === false) {
            throw new \RuntimeException('Traffic batch move could not be confirmed.');
        }

        $entry['member'] = $member;
        $data = $kind === 'hash'
            ? ($redis->hgetall($processingKey) ?: [])
            : ($redis->zrange($processingKey, 0, -1, true) ?: []);
        return [$entry, $data];
    }

    public function record(string $batchId, string $stream): void
    {
        DB::table('v2_processing_batch')->insert([
            'batch_id' => $batchId,
            'stream' => substr($stream, 0, 96),
            'created_at' => time(),
        ]);
    }

    public function pruneReceipts(int $batchSize = 5000, int $maxRows = 50000): int
    {
        $members = Redis::connection()->smembers(self::REGISTRY_KEY);
        if (!is_array($members)) {
            Log::warning('计费批次登记读取异常，本轮保留所有去重凭据');
            return 0;
        }

        $active = [];
        foreach ($members as $member) {
            $entry = json_decode((string) $member, true);
            if (!is_array($entry) || !is_string($entry['batch_id'] ?? null) || $entry['batch_id'] === '') {
                Log::warning('计费批次登记异常，本轮保留所有去重凭据');
                return 0;
            }
            $active[$entry['batch_id']] = true;
        }

        // A pending Redis batch may replay; keep its database receipt regardless of age.
        $batchSize = max(100, min($batchSize, 5000));
        $maxRows = max($batchSize, $maxRows);
        $cutoff = time() - 86400;
        $cursorCreatedAt = 0;
        $cursorId = 0;
        $deleted = 0;
        do {
            $rows = DB::table('v2_processing_batch')
                ->where('created_at', '<', $cutoff)
                ->when($cursorId > 0, function ($query) use ($cursorCreatedAt, $cursorId) {
                    $query->where(function ($after) use ($cursorCreatedAt, $cursorId) {
                        $after->where('created_at', '>', $cursorCreatedAt)
                            ->orWhere(function ($sameTime) use ($cursorCreatedAt, $cursorId) {
                                $sameTime->where('created_at', $cursorCreatedAt)->where('id', '>', $cursorId);
                            });
                    });
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->get(['id', 'batch_id', 'created_at']);
            if ($rows->isEmpty()) {
                break;
            }
            $last = $rows->last();
            $cursorCreatedAt = (int) $last->created_at;
            $cursorId = (int) $last->id;
            $ids = $rows->filter(static fn ($row) => !isset($active[(string) $row->batch_id]))
                ->take($maxRows - $deleted)
                ->pluck('id')
                ->all();
            if ($ids) {
                $deleted += DB::table('v2_processing_batch')->whereIn('id', $ids)->where('created_at', '<', $cutoff)->delete();
            }
        } while ($rows->count() === $batchSize && $deleted < $maxRows);

        return $deleted;
    }

    public function complete(?array $entry): void
    {
        if (!$entry) {
            return;
        }
        $redis = Redis::connection();
        $redis->del($entry['processing_key']);
        $redis->srem(self::REGISTRY_KEY, $entry['member'] ?? $this->encodeEntry($entry));
    }

    public function restore(?array $entry): void
    {
        if (!$entry) {
            return;
        }
        $redis = Redis::connection();
        $processingKey = (string)$entry['processing_key'];
        if ($redis->exists($processingKey)) {
            $this->mergeAtomically(
                (string)$entry['source_key'],
                $processingKey,
                (string)$entry['kind']
            );
        }
        $redis->srem(self::REGISTRY_KEY, $entry['member'] ?? $this->encodeEntry($entry));
    }

    public function recover(): void
    {
        $redis = Redis::connection();
        $members = $redis->smembers(self::REGISTRY_KEY) ?: [];
        if (!$members) {
            return;
        }

        $entries = [];
        foreach ($members as $member) {
            $entry = json_decode((string)$member, true);
            if (!is_array($entry) || empty($entry['batch_id']) || empty($entry['processing_key'])) {
                $redis->srem(self::REGISTRY_KEY, $member);
                continue;
            }
            $entry['member'] = (string)$member;
            $entries[] = $entry;
        }

        try {
            $batchIds = [];
            foreach ($entries as $entry) {
                $batchIds[(string) $entry['batch_id']] = true;
            }
            $completed = [];
            $readReceipts = static function (array $ids) use (&$completed): void {
                foreach (DB::table('v2_processing_batch')->whereIn('batch_id', $ids)->pluck('batch_id') as $id) {
                    $completed[(string) $id] = true;
                }
            };
            $ids = [];
            foreach ($batchIds as $id => $unused) {
                $ids[] = (string) $id;
                if (count($ids) === self::RECEIPT_LOOKUP_BATCH_SIZE) {
                    $readReceipts($ids);
                    $ids = [];
                }
            }
            if ($ids) {
                $readReceipts($ids);
            }
        } catch (\Throwable $e) {
            Log::error('计费批次恢复检查失败', ['exception' => $e]);
            return;
        }
        unset($members, $batchIds, $ids, $readReceipts);

        foreach ($entries as $entry) {
            try {
                if (isset($completed[$entry['batch_id']])) {
                    $this->complete($entry);
                } else {
                    $this->restore($entry);
                }
            } catch (\Throwable $e) {
                Log::error('计费批次恢复失败', [
                    'batch_id' => $entry['batch_id'],
                    'exception' => $e,
                ]);
            }
        }
    }

    private function mergeAtomically(string $sourceKey, string $processingKey, string $kind): void
    {
        $redis = Redis::connection();
        if ($kind === 'hash') {
            $script = <<<'LUA'
local sourceTtl = redis.call('PTTL', KEYS[1])
local processingTtl = redis.call('PTTL', KEYS[2])
local values = redis.call('HGETALL', KEYS[2])
for i = 1, #values, 2 do
    redis.call('HINCRBY', KEYS[1], values[i], values[i + 1])
end
redis.call('DEL', KEYS[2])
local ttl = math.max(sourceTtl, processingTtl)
if ttl > 0 then redis.call('PEXPIRE', KEYS[1], ttl) end
return #values / 2
LUA;
        } else {
            $script = <<<'LUA'
local sourceTtl = redis.call('PTTL', KEYS[1])
local processingTtl = redis.call('PTTL', KEYS[2])
local values = redis.call('ZRANGE', KEYS[2], 0, -1, 'WITHSCORES')
for i = 1, #values, 2 do
    redis.call('ZINCRBY', KEYS[1], values[i + 1], values[i])
end
redis.call('DEL', KEYS[2])
local ttl = math.max(sourceTtl, processingTtl)
if ttl > 0 then redis.call('PEXPIRE', KEYS[1], ttl) end
return #values / 2
LUA;
        }
        $redis->eval($script, 2, $sourceKey, $processingKey);
    }

    private function encodeEntry(array $entry): string
    {
        unset($entry['member']);
        return json_encode($entry, JSON_UNESCAPED_SLASHES);
    }
}
