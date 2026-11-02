<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_domain_traffic', function (Blueprint $table) {
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('day');
            $table->string('domain', 253);
            $table->unsignedBigInteger('upload')->default(0);
            $table->unsignedBigInteger('download')->default(0);
            $table->unique(['node_id', 'day', 'domain'], 'domain_traffic_bucket');
            $table->index('day');
        });
        Schema::create('v2_domain_traffic_receipt', function (Blueprint $table) {
            $table->unsignedInteger('node_id');
            $table->string('batch_id', 32);
            $table->unsignedInteger('created_at')->index();
            $table->primary(['node_id', 'batch_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_domain_traffic_receipt');
        Schema::dropIfExists('v2_domain_traffic');
    }
};
