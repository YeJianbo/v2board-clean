<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentReceipts extends Migration
{
    public function up(): void
    {
        Schema::create('v2_payment_receipt', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedInteger('payment_id');
            $table->string('trade_no', 255)->charset('binary')->nullable();
            $table->string('callback_no', 255)->charset('binary')->nullable();
            $table->char('fingerprint', 64);
            $table->unsignedBigInteger('body_bytes');
            $table->unsignedBigInteger('first_seen_at');
            $table->unsignedBigInteger('last_seen_at');
            $table->unsignedBigInteger('seen_count')->default(1);
            $table->longText('encrypted_snapshot');
            $table->unique(['payment_id', 'fingerprint'], 'payment_receipt_identity');
            $table->index(['trade_no', 'id'], 'payment_receipt_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_payment_receipt');
    }
}
