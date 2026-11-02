<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateTrafficInbox extends Migration
{
    public function up(): void
    {
        Schema::create('v2_traffic_inbox', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('received_at');
            $table->longText('payload');
        });
        Schema::create('v2_traffic_reset_checkpoint', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedInteger('user_id')->primary();
            $table->unsignedBigInteger('inbox_id');
        });
        Schema::create('v2_traffic_inbox_sequence', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id');
        });
        DB::table('v2_traffic_inbox_sequence')->insert(['id' => 1, 'last_id' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_traffic_reset_checkpoint');
        Schema::dropIfExists('v2_traffic_inbox');
        Schema::dropIfExists('v2_traffic_inbox_sequence');
    }
}
