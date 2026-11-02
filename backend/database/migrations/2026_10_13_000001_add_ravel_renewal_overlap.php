<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRavelRenewalOverlap extends Migration
{
    public function up(): void
    {
        Schema::table('v2_ravel_credential', function (Blueprint $table) {
            $table->longText('renewal_credentials')->nullable();
            $table->unsignedBigInteger('renewal_expires_at')->default(0)->index();
        });
    }

    public function down(): void
    {
        Schema::table('v2_ravel_credential', function (Blueprint $table) {
            $table->dropIndex(['renewal_expires_at']);
            $table->dropColumn(['renewal_credentials', 'renewal_expires_at']);
        });
    }
}
