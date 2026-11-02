<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_v2node', function (Blueprint $table) {
            $table->string('ravel_authority', 255)->nullable();
            $table->string('ravel_path', 2048)->nullable();
            $table->string('ravel_gateway_group', 8)->nullable();
            $table->string('ravel_masquerade', 2048)->nullable();
            $table->text('ravel_settings')->nullable();
        });
        Schema::create('v2_ravel_credential', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $group = $table->string('gateway_group', 8);
            if (DB::connection()->getDriverName() === 'mysql') $group->charset('ascii')->collation('ascii_bin');
            $table->string('user_uuid', 36);
            $table->string('credential_id', 32)->unique();
            $table->text('capability_key');
            $table->unsignedInteger('key_version');
            $table->unsignedBigInteger('not_before');
            $table->unsignedBigInteger('not_after');
            $table->unique(['user_id', 'gateway_group'], 'ravel_user_gateway_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ravel_credential');
        Schema::table('v2_server_v2node', fn (Blueprint $table) => $table->dropColumn([
            'ravel_authority', 'ravel_path', 'ravel_gateway_group', 'ravel_masquerade', 'ravel_settings',
        ]));
    }
};
