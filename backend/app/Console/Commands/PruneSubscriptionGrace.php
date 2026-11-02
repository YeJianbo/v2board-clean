<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneSubscriptionGrace extends Command
{
    protected $signature = 'subscription:prune-grace';
    protected $description = 'Erase expired subscription secrets and Ravel credentials';

    public function handle(): int
    {
        if (Schema::hasTable('v2_ravel_credential') && Schema::hasColumn('v2_ravel_credential', 'renewal_expires_at')) {
            $credentials = DB::table('v2_ravel_credential')->where('renewal_expires_at', '>', 0)->where('renewal_expires_at', '<=', time())
                ->orderBy('renewal_expires_at')->limit(1000)->pluck('credential_id');
            if ($credentials->isNotEmpty()) {
                DB::table('v2_ravel_credential')->whereIn('credential_id', $credentials)->where('renewal_expires_at', '<=', time())
                    ->update(['renewal_credentials' => null, 'renewal_expires_at' => 0]);
            }
        }
        if (!Schema::hasTable('v2_subscription_grace')) return 0;
        // Never recycle IDs: disconnected nodes can still upload delayed traffic.
        $ids = DB::table('v2_subscription_grace')->where('expires_at', '<=', time())
            ->where('token_ciphertext', '!=', '')->orderBy('id')->limit(1000)->pluck('id');
        if (Schema::hasColumn('v2_subscription_grace', 'ravel_credentials')) {
            DB::table('v2_subscription_grace')->whereIn('id', $ids)->where('expires_at', '<=', time())
                ->whereNotNull('ravel_credentials')->update(['ravel_credentials' => '']);
        }
        DB::table('v2_subscription_grace')->whereIn('id', $ids)->where('expires_at', '<=', time())
            ->update(['token_ciphertext' => '', 'uuid' => '']);
        return 0;
    }
}
