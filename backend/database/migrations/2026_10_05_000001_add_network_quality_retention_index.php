<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'v2_machine_network_quality';
    private const INDEX = 'panelcore_machine_quality_retention_idx';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, 'recorded_at') || $this->hasTimeIndex()) {
            return;
        }
        $this->changeIndex(true);
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !$this->hasTimeIndex(self::INDEX)) {
            return;
        }
        $this->changeIndex(false);
    }

    private function hasTimeIndex(?string $name = null): bool
    {
        $connection = DB::connection();
        $fullTable = $connection->getTablePrefix() . self::TABLE;
        if ($connection->getDriverName() === 'mysql') {
            $sql = 'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND column_name = ? AND seq_in_index = 1';
            $bindings = [$connection->getDatabaseName(), $fullTable, 'recorded_at'];
            if ($name !== null) {
                $sql .= ' AND index_name = ?';
                $bindings[] = $name;
            }
            return DB::selectOne($sql . ' LIMIT 1', $bindings) !== null;
        }
        if ($connection->getDriverName() === 'sqlite') {
            foreach (DB::select('PRAGMA index_list("' . str_replace('"', '""', $fullTable) . '")') as $index) {
                if ($name !== null && $index->name !== $name) continue;
                $columns = DB::select('PRAGMA index_info("' . str_replace('"', '""', $index->name) . '")');
                if ($columns && $columns[0]->name === 'recorded_at') return true;
            }
            return false;
        }
        throw new RuntimeException('Unsupported network quality index driver');
    }

    private function changeIndex(bool $add): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            $table = str_replace('`', '``', $connection->getTablePrefix() . self::TABLE);
            $operation = $add ? 'ADD INDEX `' . self::INDEX . '` (`recorded_at`)' : 'DROP INDEX `' . self::INDEX . '`';
            DB::statement('ALTER TABLE `' . $table . '` ' . $operation . ', ALGORITHM=INPLACE, LOCK=NONE');
            return;
        }
        Schema::table(self::TABLE, static function (Blueprint $table) use ($add): void {
            if ($add) $table->index('recorded_at', self::INDEX);
            else $table->dropIndex(self::INDEX);
        });
    }
};
