<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTrafficReportReceipts extends Migration
{
    public function up(): void
    {
        Schema::create('v2_traffic_report_receipt', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedInteger('server_id');
            $table->string('protocol', 32);
            $table->char('batch_id', 32);
            $table->char('payload_digest', 64);
            $table->unsignedBigInteger('inbox_id');
            $table->primary(['server_id', 'protocol', 'batch_id'], 'traffic_report_receipt_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_traffic_report_receipt');
    }
}
