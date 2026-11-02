<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_traffic_archive', function (Blueprint $table) {
            $table->string('source', 40);
            $table->string('subject', 320);
            $table->unsignedBigInteger('upload')->default(0);
            $table->unsignedBigInteger('download')->default(0);
            $table->primary(['source', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_traffic_archive');
    }
};
