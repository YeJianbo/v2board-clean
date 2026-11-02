<?php

namespace App\Models\Builders;

use App\Models\ServerGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Validation\ValidationException;

class GroupReferenceBuilder extends Builder
{
    private function groupIds($value): array
    {
        if ($value instanceof Expression) {
            throw ValidationException::withMessages(['group_id' => '请使用明确的权限组ID更新绑定']);
        }
        if (($this->getModel()->getCasts()['group_id'] ?? null) === 'array') {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }
            $values = (array) $value;
        } else {
            $values = [$value];
        }
        $ids = [];
        foreach ($values as $id) {
            if (is_scalar($id) && (int) $id > 0) $ids[(int) $id] = (int) $id;
        }
        return array_values($ids);
    }

    private function groupRows(array $values): array
    {
        return $values && is_array(reset($values)) ? $values : [$values];
    }

    private function withGroupReferences(array $rows, callable $write, bool $existingUpdate = false)
    {
        $ids = [];
        foreach ($rows as $row) {
            if (array_key_exists('group_id', $row)) {
                foreach ($this->groupIds($row['group_id']) as $id) $ids[$id] = $id;
            }
        }
        if (!$ids) return $write();
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        $connection = $this->getQuery()->getConnection();
        return $connection->transaction(function () use ($ids, $write, $existingUpdate) {
            $group = new ServerGroup();
            $group->setConnection($this->getModel()->getConnectionName());
            $found = $group->newQuery()->whereIn('id', $ids)->orderBy('id')->sharedLock()->pluck('id')->all();
            $missing = array_values(array_diff($ids, array_map('intval', $found)));
            if ($missing) {
                if (!$existingUpdate || !$this->retainsMissingGroups($missing)) {
                    throw ValidationException::withMessages(['group_id' => '权限组不存在，请刷新后重新选择']);
                }
            }
            return $write();
        }, 3);
    }

    private function retainsMissingGroups(array $missing): bool
    {
        // Lock only the exceptional legacy path so concurrent edits cannot turn a retained ID into a new binding.
        $query = clone $this;
        $column = $this->getModel()->qualifyColumn('group_id');
        foreach ($query->select($column . ' as group_id')->lockForUpdate()->toBase()->cursor() as $row) {
            if (array_diff($missing, $this->groupIds($row->group_id))) return false;
        }
        return true;
    }

    public function insert(array $values)
    {
        return $this->withGroupReferences($this->groupRows($values), fn () => $this->toBase()->insert($values));
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return $this->withGroupReferences([$values], fn () => $this->toBase()->insertGetId($values, $sequence));
    }

    public function insertOrIgnore(array $values)
    {
        return $this->withGroupReferences($this->groupRows($values), fn () => $this->toBase()->insertOrIgnore($values));
    }

    public function update(array $values)
    {
        return $this->withGroupReferences([$values], fn () => parent::update($values), true);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return $this->withGroupReferences($this->groupRows($values), fn () => parent::upsert($values, $uniqueBy, $update));
    }

    public function insertUsing(array $columns, $query)
    {
        if (in_array('group_id', $columns, true)) {
            throw ValidationException::withMessages(['group_id' => '请使用可校验权限组的分批插入方式']);
        }
        return $this->toBase()->insertUsing($columns, $query);
    }
}
