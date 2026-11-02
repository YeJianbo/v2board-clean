<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMachineAddressTracking extends Migration
{
    public function up(): void
    {
        Schema::table('v2_machine', function (Blueprint $table) {
            $table->boolean('auto_update_ipv4')->default(false);
            $table->boolean('auto_update_ipv6')->default(false);
            $table->boolean('ip_change_notify')->default(true);
            $table->text('ip_tracking')->nullable();
            $table->unsignedInteger('node_token_scoped_at')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('v2_machine', function (Blueprint $table) {
            $table->dropColumn(['auto_update_ipv4', 'auto_update_ipv6', 'ip_change_notify', 'ip_tracking', 'node_token_scoped_at']);
        });
    }
}
