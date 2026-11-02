<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRavelSubscriptionGrace extends Migration
{
    public function up(): void
    {
        Schema::table('v2_subscription_grace', function (Blueprint $table) {
            $table->longText('ravel_credentials')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_subscription_grace', function (Blueprint $table) {
            $table->dropColumn('ravel_credentials');
        });
    }
}
