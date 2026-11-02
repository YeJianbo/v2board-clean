<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCloudflareCredentials extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_cloudflare_credential')) {
            Schema::create('v2_cloudflare_credential', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 100)->unique();
                $table->text('encrypted_api_token');
                $table->string('account_id', 32)->nullable();
                $table->json('allowed_zone_ids');
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->string('verification_status', 20)->default('unverified');
                $table->unsignedInteger('verified_at')->nullable();
                $table->text('verification_error')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
        if (Schema::hasTable('v2_machine') && !Schema::hasColumn('v2_machine', 'ddns_credential_id')) {
            Schema::table('v2_machine', function (Blueprint $table) {
                $table->unsignedBigInteger('ddns_credential_id')->nullable()->index();
                $table->foreign('ddns_credential_id')->references('id')->on('v2_cloudflare_credential')->onDelete('restrict');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('v2_machine') && Schema::hasColumn('v2_machine', 'ddns_credential_id')) {
            Schema::table('v2_machine', function (Blueprint $table) {
                $table->dropForeign(['ddns_credential_id']);
                $table->dropColumn('ddns_credential_id');
            });
        }
        Schema::dropIfExists('v2_cloudflare_credential');
    }
}
