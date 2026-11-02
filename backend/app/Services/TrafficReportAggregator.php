<?php

namespace App\Services;

use App\Support\TrafficReport;

class TrafficReportAggregator
{
    public function aggregate(iterable $reports, array $quotaCutoffs = []): array
    {
        $uploads = $downloads = $statistics = [];
        foreach ($reports as $report) {
            $receivedAt = (int) ($report['received_at'] ?? 0);
            if ($receivedAt <= 0) {
                throw new \UnexpectedValueException('Missing traffic report time.');
            }
            $rate = $report['server']['rate'] ?? 1;
            $data = TrafficReport::normalize($report['data'], $rate);
            $serverId = (int) ($report['server']['id'] ?? 0);
            $protocol = strtolower(trim((string) ($report['protocol'] ?? '')));
            $formattedRate = number_format((float) $rate, 2, '.', '');
            $day = strtotime(date('Y-m-d', $receivedAt));
            $periods = [
                ['v2_stat_user_server', $day, 'd'],
                ['v2_stat_user_server_hour', strtotime(date('Y-m-d H:00:00', $receivedAt)), 'h'],
                ['v2_stat_user_server_minute', strtotime(date('Y-m-d H:i:00', $receivedAt)), 'm'],
            ];
            foreach ($data as $userId => [$upload, $download]) {
                if ($upload === 0 && $download === 0) continue;
                // Match existing billing: round each report before combining its charged bytes.
                if (!isset($quotaCutoffs[$userId]) || (int) ($report['id'] ?? 0) > $quotaCutoffs[$userId]) {
                    $uploads[$userId] = $this->add($uploads[$userId] ?? 0, (int) ($upload * $rate));
                    $downloads[$userId] = $this->add($downloads[$userId] ?? 0, (int) ($download * $rate));
                }
                $this->addStat($statistics, 'v2_stat_user', $day, 'd',
                    ['user_id' => $userId, 'server_rate' => $formattedRate], $upload, $download);
                if ($serverId <= 0 || $protocol === '') continue;
                $this->addStat($statistics, 'v2_stat_server', $day, 'd',
                    ['server_id' => $serverId, 'server_type' => $protocol], $upload, $download);
                foreach ($periods as [$table, $recordAt, $recordType]) {
                    $this->addStat($statistics, $table, $recordAt, $recordType, [
                        'user_id' => $userId, 'server_id' => $serverId,
                        'server_type' => $protocol, 'server_rate' => $formattedRate,
                    ], $upload, $download);
                }
            }
        }
        ksort($uploads, SORT_NUMERIC);
        ksort($downloads, SORT_NUMERIC);
        return ['uploads' => $uploads, 'downloads' => $downloads, 'statistics' => $statistics];
    }

    private function addStat(array &$statistics, string $table, int $recordAt, string $type, array $identity, int $upload, int $download): void
    {
        $key = json_encode($identity, JSON_THROW_ON_ERROR);
        $bucket = &$statistics[$table][$recordAt];
        $bucket ??= ['record_type' => $type, 'rows' => []];
        $row = $bucket['rows'][$key] ?? ($identity + ['u' => 0, 'd' => 0]);
        $row['u'] = $this->add($row['u'], $upload);
        $row['d'] = $this->add($row['d'], $download);
        $bucket['rows'][$key] = $row;
    }

    private function add(int $current, int $increment): int
    {
        if ($increment > PHP_INT_MAX - $current) {
            throw new \OverflowException('Traffic aggregation exceeds the accounting range.');
        }
        return $current + $increment;
    }
}
