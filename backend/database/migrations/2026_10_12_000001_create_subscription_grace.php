<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubscriptionGrace extends Migration
{
    public function up(): void
    {
        Schema::create('v2_subscription_grace', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->string('uuid', 36);
            $table->char('owner_token_hash', 64);
            $table->unsignedInteger('expires_at')->index();
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_grace');
    }
}
