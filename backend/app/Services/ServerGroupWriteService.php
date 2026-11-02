<?php

namespace App\Services;

use App\Models\ServerGroup;
use Illuminate\Support\Facades\DB;

class ServerGroupWriteService
{
    public function save(string $name, ?int $id): ServerGroup
    {
        return DB::transaction(static function () use ($name, $id) {
            $group = $id === null ? new ServerGroup() : ServerGroup::whereKey($id)->lockForUpdate()->firstOrFail();
            $group->name = $name;
            if (!$group->save() || !$group->exists || !$group->getKey()) {
                throw new \RuntimeException('Server group save rejected.');
            }
            return $group;
        }, 3);
    }
}
