<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMailTemplates extends Migration
{
    public function up(): void
    {
        Schema::create('v2_mail_template', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 64)->unique();
            $table->string('subject');
            $table->longText('content');
            $table->unsignedBigInteger('created_at')->nullable();
            $table->unsignedBigInteger('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_mail_template');
    }
}
