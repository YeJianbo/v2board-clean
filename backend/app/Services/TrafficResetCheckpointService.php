<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrafficResetCheckpointService
{
    public function reset(User $user): void
    {
        $this->record([(int) $user->id]);
        $user->u = 0;
        $user->d = 0;
    }

    public function watermark(): int
    {
        $watermark = DB::table('v2_traffic_inbox_sequence')->where('id', 1)->value('last_id');
        if ($watermark === null) throw new \RuntimeException('Traffic inbox sequence is unavailable.');
        return (int) $watermark;
    }

    /** Call in the transaction that resets the selected users' counters. */
    public function record(array $userIds, ?int $watermark = null): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Traffic reset checkpoint requires a transaction.');
        if (!$userIds) return;
        $watermark ??= $this->watermark();
        foreach (array_chunk(array_values(array_unique($userIds)), 250) as $ids) {
            DB::table('v2_traffic_reset_checkpoint')->insertOrIgnore(array_map(
                static fn ($id) => ['user_id' => (int) $id, 'inbox_id' => $watermark], $ids
            ));
            if ($watermark > 0) {
                DB::table('v2_traffic_reset_checkpoint')->whereIn('user_id', $ids)
                    ->where('inbox_id', '<', $watermark)->update(['inbox_id' => $watermark]);
            }
        }
    }

    public function lockForReports(array $reports): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Traffic cutoff lookup requires a transaction.');
        $ids = [];
        foreach ($reports as $report) foreach ($report['data'] as $id => $unused) $ids[(int) $id] = true;
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $cutoffs = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $existing = User::whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if (!$existing) continue;
            // A locking read sees a reset committed while we were waiting for the user lock.
            foreach (DB::table('v2_traffic_reset_checkpoint')->whereIn('user_id', $existing)
                ->orderBy('user_id')->lockForUpdate()->pluck('inbox_id', 'user_id') as $id => $cutoff) {
                $cutoffs[(int) $id] = (int) $cutoff;
            }
        }
        return $cutoffs;
    }
}
