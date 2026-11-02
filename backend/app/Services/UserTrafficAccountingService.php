<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserTrafficAccountingService
{
    public function apply(array $uploads, array $downloads, int $time): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Traffic accounting requires an active transaction.');
        }
        $userIds = array_values(array_unique(array_merge(array_keys($uploads), array_keys($downloads))));
        sort($userIds, SORT_NUMERIC);
        foreach (array_chunk($userIds, 500) as $chunk) {
            $ids = User::whereIn('id', $chunk)->orderBy('id')->pluck('id')->all();
            if (!$ids) continue;
            $casesU = $casesD = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                $upload = (int) ($uploads[$id] ?? 0);
                $download = (int) ($downloads[$id] ?? 0);
                $casesU[] = "WHEN {$id} THEN {$upload}";
                $casesD[] = "WHEN {$id} THEN {$download}";
            }
            $idList = implode(',', array_map('intval', $ids));
            $uploadCases = implode(' ', $casesU);
            $downloadCases = implode(' ', $casesD);
            DB::statement("UPDATE v2_user SET u = u + CASE id {$uploadCases} ELSE 0 END, d = d + CASE id {$downloadCases} ELSE 0 END, t = {$time}, updated_at = {$time} WHERE id IN ({$idList})");
        }
        return $userIds;
    }
}
