<?php

namespace App\Services;

use App\Models\SubscribeTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

class DomainDirectAnalysis
{
    private const MAX_DOMAIN_LIST_BYTES = 16 * 1024 * 1024;

    public function analyse(int $days, ?int $nodeId = null, ?int $startAt = null, ?int $endAt = null): array
    {
        $china = Cache::remember('domain-analysis:china-list:v1', 86400, function () {
            $payload = Yaml::parse($this->downloadDomainList())['payload'] ?? [];
            if (!is_array($payload) || count($payload) < 1000) throw new \RuntimeException('国内域名库内容异常，请稍后重试');
            return ['index' => $this->index($payload, true), 'fetched_at' => time()];
        });
        $files = SubscribeTemplate::getRuleFiles(['direct', 'proxy', 'reject']);
        abort_unless(isset($files['direct']), 409, '现有 direct.yaml 不存在，请先检查订阅子规则');
        $direct = $this->index(Yaml::parse($files['direct'])['payload']);
        $conflicts = [];
        foreach (['proxy', 'reject'] as $name) {
            $conflicts[$name] = $this->index(Yaml::parse($files[$name] ?? 'payload: []')['payload']);
        }
        $hourly = $startAt !== null && $endAt !== null && $endAt - $startAt <= 31 * 86400
            && $startAt >= time() - TrafficRetention::days('domain_hour') * 86400;
        $step = $hourly ? 3600 : 86400;
        $column = $hourly ? 'hour' : 'day';
        $query = DB::table($hourly ? 'v2_domain_traffic_hour' : 'v2_domain_traffic');
        if ($startAt !== null && $endAt !== null) {
            $query->where($column, '>=', (int) (floor($startAt / $step) * $step))
                ->where($column, '<', (int) (ceil($endAt / $step) * $step));
        } else {
            $since = (int) (floor(time() / 86400) * 86400) - ($days - 1) * 86400;
            $query->where('day', '>=', $since);
        }
        if ($nodeId) $query->where('node_id', $nodeId);
        $records = $query->selectRaw('domain, SUM(upload + download) as total')->groupBy('domain')->orderByDesc('total')->cursor();
        $rows = []; $scanned = 0; $matched = 0;
        foreach ($records as $record) {
            $scanned++;
            $domain = strtolower($record->domain);
            if (!$this->validDomain($domain)) continue;
            $reason = $this->matches($domain, $china['index']) ? '国内域名库' :
                ((str_ends_with($domain, '.cn') || str_ends_with($domain, '.xn--fiqs8s')) ? '中国域名后缀（需确认）' : null);
            if (!$reason) continue;
            $matched++;
            if (count($rows) >= 500) continue;
            $status = $this->matches($domain, $direct) ? 'covered' : 'candidate';
            foreach ($conflicts as $name => $index) {
                if ($this->matches($domain, $index)) { $status = 'conflict'; $reason .= '；命中 ' . $name . '.yaml'; }
            }
            $rows[] = ['domain' => $domain, 'total' => (int) $record->total, 'reason' => $reason, 'status' => $status];
        }
        return ['rows' => $rows, 'scanned' => $scanned, 'matched' => $matched, 'days' => $days, 'node_id' => $nodeId,
            'start_at' => $startAt, 'end_at' => $endAt, 'resolution' => $hourly ? 'hour' : 'day',
            'source_updated_at' => $china['fetched_at'], 'fingerprint' => hash('sha256', $files['direct'])];
    }

    private function downloadDomainList(): string
    {
        $deadline = microtime(true) + 25;
        $response = Http::withOptions(['connect_timeout' => 5, 'read_timeout' => 5, 'stream' => true])
            ->timeout(25)
            ->get('https://raw.githubusercontent.com/Loyalsoldier/clash-rules/release/direct.txt');
        try {
            if (!$response->successful()) {
                throw new \RuntimeException('国内域名库读取失败（HTTP ' . $response->status() . '），请稍后重试');
            }
            $length = (string) $response->header('Content-Length');
            if (ctype_digit($length) && (float) $length > self::MAX_DOMAIN_LIST_BYTES) {
                throw new \RuntimeException('国内域名库超过 16 MiB，已停止读取');
            }
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (!$stream->eof()) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('国内域名库读取超时，请稍后重试');
                }
                $chunk = $stream->read(min(65536, self::MAX_DOMAIN_LIST_BYTES - strlen($body) + 1));
                if ($chunk === '' && !$stream->eof()) {
                    throw new \RuntimeException('国内域名库读取中断，请稍后重试');
                }
                $body .= $chunk;
                if (strlen($body) > self::MAX_DOMAIN_LIST_BYTES) {
                    throw new \RuntimeException('国内域名库超过 16 MiB，已停止读取');
                }
            }
            return $body;
        } finally {
            $response->close();
        }
    }

    public function apply(array $preview, array $domains): array
    {
        $allowed = array_column(array_filter($preview['rows'], fn ($row) => $row['status'] === 'candidate'), 'domain');
        $domains = array_values(array_unique($domains));
        abort_if(array_diff($domains, $allowed), 422, '只能添加本次分析中待补充的域名');
        return Cache::lock('domain-analysis:write', 30)->block(3, function () use ($preview, $domains) {
            $files = SubscribeTemplate::getRuleFiles(['direct', 'proxy', 'reject']);
            $before = $files['direct'] ?? '';
            abort_unless(hash_equals($preview['fingerprint'], hash('sha256', $before)), 409, '直连规则已更改，请重新分析');
            $parsed = Yaml::parse($before);
            $index = $this->index($parsed['payload']);
            $conflicts = [];
            foreach (['proxy', 'reject'] as $name) {
                $conflicts[$name] = $this->index(Yaml::parse($files[$name] ?? 'payload: []')['payload']);
            }
            $added = [];
            foreach ($domains as $domain) {
                abort_unless($this->validDomain($domain), 422, '域名格式错误');
                foreach ($conflicts as $other) {
                    abort_if($this->matches($domain, $other), 409, '规则发生冲突，请重新分析');
                }
                if (!$this->matches($domain, $index)) $added[] = 'DOMAIN,' . $domain;
            }
            if (!$added) return ['added' => 0];
            // Append without discarding existing comments. Verify the resulting payload before writing.
            $after = rtrim($before) . "\n" . implode("\n", array_map(fn ($rule) => '  - ' . $rule, $added)) . "\n";
            $expected = $parsed; $expected['payload'] = array_merge($parsed['payload'], $added);
            abort_unless(Yaml::parse($after) === $expected, 409, '子规则结构不支持追加，请在配置编辑中检查');
            $backupDir = storage_path('app/domain-rule-backups');
            File::ensureDirectoryExists($backupDir, 0700);
            $backup = $backupDir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.yaml';
            File::put($backup, $before); chmod($backup, 0600);
            SubscribeTemplate::setRuleFiles(['direct' => $after]);
            return ['added' => count($added)];
        });
    }

    public function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253 && str_contains($domain, '.') && !filter_var($domain, FILTER_VALIDATE_IP)
            && (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    public function index(array $rules, bool $domainList = false): array
    {
        $index = ['exact' => [], 'suffix' => [], 'keyword' => []];
        foreach ($rules as $rule) {
            if (!is_string($rule)) continue;
            if ($domainList) {
                $suffix = str_starts_with($rule, '+.') || str_starts_with($rule, '.');
                $value = ltrim(strtolower($rule), '+.');
                if ($this->validDomain($value)) $index[$suffix ? 'suffix' : 'exact'][$value] = true;
                continue;
            }
            $parts = explode(',', $rule);
            $type = ['DOMAIN' => 'exact', 'DOMAIN-SUFFIX' => 'suffix', 'DOMAIN-KEYWORD' => 'keyword'][$parts[0]] ?? null;
            if ($type && isset($parts[1])) $index[$type][strtolower(trim($parts[1]))] = true;
        }
        return $index;
    }

    public function matches(string $domain, array $index): bool
    {
        if (isset($index['exact'][$domain])) return true;
        $part = $domain;
        while ($part !== '') {
            if (isset($index['suffix'][$part])) return true;
            $dot = strpos($part, '.');
            if ($dot === false) break;
            $part = substr($part, $dot + 1);
        }
        foreach ($index['keyword'] as $keyword => $_) if ($keyword !== '' && str_contains($domain, $keyword)) return true;
        return false;
    }
}
