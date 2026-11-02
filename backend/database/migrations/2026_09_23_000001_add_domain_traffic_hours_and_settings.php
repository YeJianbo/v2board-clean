<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_domain_traffic_hour', function (Blueprint $table) {
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('hour');
            $table->string('domain', 253);
            $table->unsignedBigInteger('upload')->default(0);
            $table->unsignedBigInteger('download')->default(0);
            $table->unique(['node_id', 'hour', 'domain'], 'domain_traffic_hour_bucket');
            $table->index('hour');
            $table->index(['domain', 'hour'], 'domain_traffic_history');
        });
        Schema::create('v2_domain_traffic_setting', function (Blueprint $table) {
            $table->string('scope', 8);
            $table->unsignedInteger('target_id');
            $table->boolean('enabled');
            $table->primary(['scope', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_domain_traffic_setting');
        Schema::dropIfExists('v2_domain_traffic_hour');
    }
};
