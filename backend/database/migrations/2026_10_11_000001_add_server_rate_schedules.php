<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TYPES = ['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'];

    public function up(): void
    {
        foreach (self::TYPES as $type) {
            $name = 'v2_server_' . $type;
            if (!Schema::hasTable($name)) continue;
            $enabledMissing = !Schema::hasColumn($name, 'rate_time_enable');
            $rulesMissing = !Schema::hasColumn($name, 'rate_time_ranges');
            if (!$enabledMissing && !$rulesMissing) continue;
            Schema::table($name, static function (Blueprint $table) use ($enabledMissing, $rulesMissing) {
                if ($enabledMissing) $table->boolean('rate_time_enable')->default(false);
                if ($rulesMissing) $table->json('rate_time_ranges')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TYPES as $type) {
            $name = 'v2_server_' . $type;
            if (!Schema::hasTable($name)) continue;
            foreach (['rate_time_enable', 'rate_time_ranges'] as $field) {
                if (Schema::hasColumn($name, $field)) Schema::table($name, static fn (Blueprint $table) => $table->dropColumn($field));
            }
        }
    }
};
