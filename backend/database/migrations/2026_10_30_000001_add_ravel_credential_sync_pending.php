<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_ravel_credential', 'sync_pending')) {
            Schema::table('v2_ravel_credential', fn (Blueprint $table) => $table->boolean('sync_pending')->default(false));
            if (Schema::hasColumn('v2_ravel_credential', 'renewal_credentials')) {
                DB::table('v2_ravel_credential')->whereNotNull('renewal_credentials')->update(['sync_pending' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('v2_ravel_credential', fn (Blueprint $table) => $table->dropColumn('sync_pending'));
    }
};
