<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPlanReferenceIndexes extends Migration
{
    private const TABLES = ['v2_user', 'v2_order', 'v2_giftcard'];

    public function up()
    {
        $connection = DB::connection();
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'plan_id') || $this->hasLeadingIndex($table)) continue;
            $name = 'panelcore_' . $table . '_plan_ref_idx';
            if ($connection->getDriverName() === 'mysql') {
                $fullTable = str_replace('`', '``', $connection->getTablePrefix() . $table);
                DB::statement('ALTER TABLE `' . $fullTable . '` ADD INDEX `' . $name . '` (`plan_id`), ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                Schema::table($table, static function (Blueprint $definition) use ($name): void { $definition->index('plan_id', $name); });
            }
        }
    }

    public function hasLeadingIndex(string $table): bool
    {
        $connection = DB::connection();
        $fullTable = $connection->getTablePrefix() . $table;
        if ($connection->getDriverName() === 'mysql') {
            return (bool) DB::selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND column_name = ? AND seq_in_index = 1 LIMIT 1',
                [$connection->getDatabaseName(), $fullTable, 'plan_id']);
        }
        if ($connection->getDriverName() === 'sqlite') {
            $quotedTable = str_replace('"', '""', $fullTable);
            foreach (DB::select('PRAGMA index_list("' . $quotedTable . '")') as $index) {
                $columns = DB::select('PRAGMA index_info("' . str_replace('"', '""', $index->name) . '")');
                if ($columns && $columns[0]->name === 'plan_id') return true;
            }
            return false;
        }
        throw new RuntimeException('Unsupported plan index driver');
    }

    public function down()
    {
        $connection = DB::connection();
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) continue;
            $name = 'panelcore_' . $table . '_plan_ref_idx';
            if ($connection->getDriverName() === 'mysql') {
                $exists = DB::selectOne('SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                    [$connection->getDatabaseName(), $connection->getTablePrefix() . $table, $name]);
                if (!$exists) continue;
                $fullTable = str_replace('`', '``', $connection->getTablePrefix() . $table);
                DB::statement('ALTER TABLE `' . $fullTable . '` DROP INDEX `' . $name . '`, ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                $indexes = DB::select('PRAGMA index_list("' . str_replace('"', '""', $connection->getTablePrefix() . $table) . '")');
                if (!array_filter($indexes, static fn ($index) => $index->name === $name)) continue;
                Schema::table($table, static function (Blueprint $definition) use ($name): void { $definition->dropIndex($name); });
            }
        }
    }
}
