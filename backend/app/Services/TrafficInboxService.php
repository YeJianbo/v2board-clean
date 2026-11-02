<?php

namespace App\Services;

use App\Support\TrafficReport;
use App\Support\ServerRateSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrafficInboxService
{
    public function status(): array
    {
        try {
            $count = DB::query()->fromSub(DB::table('v2_traffic_inbox')->select('id')->limit(10001), 'pending')->count();
            $oldest = $count ? DB::table('v2_traffic_inbox')->orderBy('id')->value('received_at') : null;
            $age = $oldest === null ? 0 : max(0, time() - (int) $oldest);
            return ['available' => true, 'pending' => min(10000, $count), 'pending_capped' => $count > 10000,
                'oldest_received_at' => $oldest === null ? null : (int) $oldest,
                'backlog_age_seconds' => $age, 'delayed' => $age > 180];
        } catch (\Throwable $exception) {
            return ['available' => false, 'pending' => null, 'pending_capped' => false,
                'oldest_received_at' => null, 'backlog_age_seconds' => null, 'delayed' => null];
        }
    }

    public function store(array $data, array $server, string $protocol, ?int $receivedAt = null, ?string $batchId = null): ?int
    {
        if ($batchId !== null && !preg_match('/\A[0-9a-f]{32}\z/', $batchId)) {
            abort(422, 'Invalid traffic batch ID.');
        }
        $protocol = strtolower(trim($protocol));
        $receivedAt ??= time();
        $validationError = null;
        try {
            $rate = ServerRateSchedule::resolve($server, $receivedAt);
            $data = TrafficReport::normalize($data, $rate);
        } catch (ValidationException $error) {
            if ($batchId === null) throw $error;
            // A committed receipt wins over a new schedule. Validate raw bytes for its identity first.
            $data = TrafficReport::normalize($data);
            $validationError = $error;
            $rate = null;
        }
        $server = ['id' => (int) ($server['id'] ?? 0), 'rate' => $rate];
        $data = array_filter($data, static fn (array $traffic) => $traffic[0] > 0 || $traffic[1] > 0);
        if (!$data) {
            if ($validationError !== null) throw $validationError;
            return null;
        }
        $receiptKey = $batchId === null ? null : ['server_id' => $server['id'], 'protocol' => $protocol, 'batch_id' => $batchId];
        $digest = null;
        if ($receiptKey !== null) {
            ksort($data, SORT_NUMERIC);
            // Detect accidental batch-ID reuse with different bytes, independent of later rate changes.
            $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        }
        return DB::transaction(function () use ($data, $server, $protocol, $receivedAt, $receiptKey, $digest, $validationError): int {
            // MySQL 5.7 can reuse AUTO_INCREMENT IDs after this transient table is emptied and restarted.
            $last = DB::table('v2_traffic_inbox_sequence')->where('id', 1)->lockForUpdate()->value('last_id');
            if ($receiptKey !== null) {
                // The existing sequence lock serializes receipt lookup and insertion with inbox acceptance.
                $receipt = DB::table('v2_traffic_report_receipt')->where($receiptKey)->lockForUpdate()->first();
                if ($receipt !== null) {
                    if (!hash_equals($receipt->payload_digest, $digest)) abort(409, 'Traffic batch ID was reused with different data.');
                    return (int) $receipt->inbox_id;
                }
            }
            if ($validationError !== null) throw $validationError;
            if ($last === null || (int) $last < 0 || (int) $last >= PHP_INT_MAX) {
                throw new \RuntimeException('Traffic inbox sequence is unavailable or exhausted.');
            }
            // Keep receipt identity in node IDs and read owner bindings in one transaction snapshot.
            $payload = json_encode([
                'data' => app(SubscriptionGraceService::class)->traffic($data),
                'server' => $server,
                'protocol' => $protocol,
            ], JSON_THROW_ON_ERROR);
            $id = (int) $last + 1;
            DB::table('v2_traffic_inbox')->insert(['id' => $id, 'received_at' => $receivedAt, 'payload' => $payload]);
            if ($receiptKey !== null) {
                DB::table('v2_traffic_report_receipt')->insert($receiptKey + ['payload_digest' => $digest, 'inbox_id' => $id]);
            }
            DB::table('v2_traffic_inbox_sequence')->where('id', 1)->update(['last_id' => $id]);
            return $id;
        }, 3);
    }

    /** The callback must perform only database accounting on the default connection. */
    public function consumeBatch(callable $apply, int $limit = 100): int
    {
        $limit = max(1, min(100, $limit));
        // Discover without range locks so accounting does not lock the insertion gap at the queue tail.
        $candidates = DB::table('v2_traffic_inbox')->select('id')->selectRaw('LENGTH(payload) AS payload_bytes')
            ->orderBy('id')->limit($limit)->get();
        $ids = [];
        $bytes = 0;
        foreach ($candidates as $candidate) {
            // Bound decoded batch memory while still allowing an oversized oldest report to make progress.
            if ($ids && $bytes + (int) $candidate->payload_bytes > 4 * 1024 * 1024) break;
            $ids[] = $candidate->id;
            $bytes += (int) $candidate->payload_bytes;
        }
        if (!$ids) return 0;
        return DB::transaction(function () use ($apply, $ids): int {
            $rows = DB::table('v2_traffic_inbox')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            // Another consumer may have committed these candidates before this transaction acquired them.
            if ($rows->isEmpty()) return 0;
            $reports = [];
            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || !is_array($payload['data'] ?? null) ||
                    !is_array($payload['server'] ?? null) || !is_string($payload['protocol'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid stored traffic report.');
                }
                $payload['data'] = TrafficReport::normalize($payload['data'], $payload['server']['rate'] ?? 1);
                $payload['received_at'] = (int) $row->received_at;
                $payload['id'] = (int) $row->id;
                $reports[] = $payload;
            }
            $apply($reports);
            // Deletion is the durable completion marker; a rollback preserves the original reports.
            DB::table('v2_traffic_inbox')->whereIn('id', $rows->pluck('id')->all())->delete();
            return count($reports);
        }, 3);
    }
}
