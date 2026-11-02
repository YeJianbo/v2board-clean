<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'v2_stat_user_server_minute';

    private const INDEXES = [
        'v2_stat_user_server_minute_user_id_index' => ['user_id'],
        'server_minute_type_index' => ['server_id', 'server_type'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if ($this->hasIndex($name)) {
                Schema::table(self::TABLE, static function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if (!$this->hasIndex($name)) {
                Schema::table(self::TABLE, static function (Blueprint $table) use ($name, $columns): void {
                    $table->index($columns, $name);
                });
            }
        }
    }

    private function hasIndex(string $name): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (DB::select('PRAGMA index_list(' . self::TABLE . ')') as $index) {
                if ($index->name === $name) {
                    return true;
                }
            }
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', self::TABLE)
            ->where('index_name', $name)
            ->exists();
    }
};
