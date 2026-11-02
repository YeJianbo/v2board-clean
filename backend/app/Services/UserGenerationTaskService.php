<?php

namespace App\Services;

use App\Jobs\GenerateUsersJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class UserGenerationTaskService
{
    private const TTL = 3600;
    private UserGenerationService $generator;

    public function __construct(UserGenerationService $generator) { $this->generator = $generator; }

    private function key(string $id, string $part): string { return 'user_generation:' . $part . ':' . $id; }

    public function start(int $owner, array $input, bool $legacy = false): array
    {
        abort_unless($owner > 0, 403);
        // Durable receipts use an existing CHAR(32); scope the retry nonce to its administrator.
        $id = substr(hash('sha256', $owner . ':' . strtolower($input['request_id'])), 0, 32);
        return Cache::lock('user_generation:submit:' . $owner, 10)->block(3, function () use ($owner, $id, $input, $legacy): array {
            if (Cache::has($this->key($id, 'meta'))) return $this->status($owner, $id);
            if ($this->generator->committed($id)) abort(409, '该任务已完成且结果已过期，请使用新的任务编号');
            $last = $this->status($owner);
            if ($last && in_array($last['state'], ['queued', 'running'], true)) abort(409, '已有批量生成任务正在处理');
            $spec = $this->generator->specification($input, $legacy);
            $meta = ['batch_id' => $id, 'request_id' => strtolower($input['request_id']), 'owner_id' => $owner, 'state' => 'queued', 'total' => count($spec['identities']),
                'prepared' => 0, 'created' => 0, 'error' => null];
            Cache::put($this->key($id, 'spec'), Crypt::encryptString(json_encode($spec, JSON_THROW_ON_ERROR)), self::TTL);
            Cache::put($this->key($id, 'meta'), $meta, self::TTL);
            Cache::put('user_generation:last:' . $owner, $id, self::TTL);
            try { Bus::dispatch(new GenerateUsersJob($id)); }
            catch (\Throwable $exception) {
                $meta['state'] = 'failed'; $meta['error'] = '任务提交失败，请重试';
                Cache::put($this->key($id, 'meta'), $meta, self::TTL);
                Log::warning('User generation enqueue failed', ['exception_type' => get_class($exception)]);
                throw new \RuntimeException($meta['error']);
            }
            return $this->publicMeta($meta);
        });
    }

    private function publicMeta(array $meta): array { unset($meta['owner_id']); return $meta; }

    public function status(int $owner, ?string $id = null): ?array
    {
        abort_unless($owner > 0, 403);
        $latest = $id === null;
        if ($id === null) $id = Cache::get('user_generation:last:' . $owner);
        if (!$id) return null;
        $meta = Cache::get($this->key($id, 'meta'));
        if ($latest && !is_array($meta)) {
            Cache::forget('user_generation:last:' . $owner);
            return null;
        }
        abort_unless(is_array($meta) && (int) $meta['owner_id'] === $owner, 404, '任务不存在或结果已过期');
        if ($meta['state'] !== 'completed' && $meta['prepared'] === $meta['total'] && $this->generator->committed($id)) {
            $this->complete($id, $meta, $this->generator->recover($this->spec($id)));
            $meta = Cache::get($this->key($id, 'meta'));
        }
        return $this->publicMeta($meta);
    }

    private function spec(string $id): array
    {
        $encrypted = Cache::get($this->key($id, 'spec'));
        if (!is_string($encrypted)) throw new \RuntimeException('Generation input expired');
        return json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
    }

    public function run(string $id): void
    {
        Cache::lock($this->key($id, 'work'), 300)->block(2, function () use ($id): void {
            $meta = Cache::get($this->key($id, 'meta'));
            if (!is_array($meta) || in_array($meta['state'], ['completed', 'failed'], true)) return;
            $meta['state'] = 'running'; Cache::put($this->key($id, 'meta'), $meta, self::TTL);
            try {
                $result = $this->generator->execute($this->spec($id), $id, function (int $prepared) use ($id, &$meta): void {
                    $meta['prepared'] = $prepared;
                    if ($prepared % 10 === 0 || $prepared === $meta['total']) Cache::put($this->key($id, 'meta'), $meta, self::TTL);
                });
                $this->complete($id, $meta, $result);
            } catch (\Throwable $exception) {
                if ($this->generator->committed($id)) { $this->complete($id, $meta, $this->generator->recover($this->spec($id))); return; }
                $meta['state'] = 'failed'; $meta['error'] = '生成失败，未创建新账户，请检查邮箱或套餐后重试';
                Cache::put($this->key($id, 'meta'), $meta, self::TTL);
                Log::warning('User generation task failed', ['exception_type' => get_class($exception)]);
            }
        });
    }

    private function complete(string $id, array $meta, array $result): void
    {
        Cache::put($this->key($id, 'result'), Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), self::TTL);
        $meta['state'] = 'completed'; $meta['prepared'] = $meta['total']; $meta['created'] = $meta['total']; $meta['error'] = null;
        Cache::put($this->key($id, 'meta'), $meta, self::TTL);
    }

    public function failure(string $id): void
    {
        $meta = Cache::get($this->key($id, 'meta'));
        if (!is_array($meta) || $meta['state'] === 'completed') return;
        if ($this->generator->committed($id)) { $this->complete($id, $meta, $this->generator->recover($this->spec($id))); return; }
        $meta['state'] = 'failed'; $meta['error'] = '生成任务中断，未创建新账户，请重试';
        Cache::put($this->key($id, 'meta'), $meta, self::TTL);
    }

    public function download(int $owner, string $id)
    {
        $status = $this->status($owner, $id);
        abort_unless($status['state'] === 'completed', 409, '任务尚未完成');
        $encrypted = Cache::get($this->key($id, 'result'));
        abort_unless(is_string($encrypted), 404, '结果已过期');
        $result = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        return $this->generator->csv($result['data']);
    }
}
