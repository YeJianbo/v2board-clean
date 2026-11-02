<?php

namespace App\Services;

use RuntimeException;

class CloudflareDnsService extends CloudflareApiClient
{
    public function resolveZoneId(string $zoneName): string
    {
        $payload = $this->request('GET', '/zones', ['name' => $zoneName], '获取 Cloudflare Zone 失败');
        if (!is_array($payload['result'] ?? null)) {
            throw new RuntimeException('Cloudflare Zone 响应无效');
        }
        foreach ($payload['result'] ?? [] as $zone) {
            if (is_array($zone) && strcasecmp((string) ($zone['name'] ?? ''), $zoneName) === 0) {
                $zoneId = trim((string) ($zone['id'] ?? ''));
                if ($zoneId !== '') {
                    return $zoneId;
                }
            }
        }
        throw new RuntimeException('未找到 Cloudflare Zone');
    }

    public function findRecords(string $zoneId, string $type, string $name): array
    {
        $payload = $this->request('GET', '/zones/' . rawurlencode($zoneId) . '/dns_records', [
            'type' => $type,
            'name' => $name,
            'per_page' => 100,
        ], '获取 Cloudflare 记录失败');

        if (!is_array($payload['result'] ?? null)) {
            throw new RuntimeException('Cloudflare 记录响应无效');
        }
        if ((int) ($payload['result_info']['total_pages'] ?? 1) > 1) {
            throw new RuntimeException('同名 Cloudflare 记录过多，请先明确要更新的记录');
        }

        return $payload['result'];
    }

    public function upsertAddressRecord(string $zoneId, array $record): array
    {
        $type = strtoupper((string) ($record['type'] ?? ''));
        $filter = $type === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if (!in_array($type, ['A', 'AAAA'], true) || !filter_var($record['content'] ?? '', FILTER_VALIDATE_IP, $filter)) {
            throw new RuntimeException('Cloudflare 地址记录无效');
        }

        $records = $this->findRecords($zoneId, $type, (string) $record['name']);
        if (count($records) > 1) {
            // Do not silently replace one member of a multi-address DNS record.
            throw new RuntimeException('同名 Cloudflare 地址记录不唯一，请先明确要更新的记录');
        }

        $recordId = trim((string) ($records[0]['id'] ?? ''));
        if ($records && $recordId === '') {
            throw new RuntimeException('Cloudflare 记录编号无效');
        }
        $path = '/zones/' . rawurlencode($zoneId) . '/dns_records';
        $payload = $this->request(
            $recordId !== '' ? 'PUT' : 'POST',
            $recordId !== '' ? $path . '/' . rawurlencode($recordId) : $path,
            $record,
            '更新 Cloudflare 记录失败'
        );

        if (!is_array($payload['result'] ?? null) || trim((string) ($payload['result']['id'] ?? '')) === '') {
            throw new RuntimeException('Cloudflare 更新响应无效');
        }

        return $payload['result'];
    }

}
