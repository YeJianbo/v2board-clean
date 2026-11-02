<?php

namespace App\Models\Builders;

use App\Models\Plan;
use Illuminate\Validation\ValidationException;

class PlanReferenceBuilder extends GroupReferenceBuilder
{
    private function withReferences(array $rows, callable $write)
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!array_key_exists('plan_id', $row)) continue;
            $id = $row['plan_id'];
            if ($id === null || $id === 0 || $id === '0') continue;
            if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int) $id <= 0) {
                throw ValidationException::withMessages(['plan_id' => '订阅计划格式不正确']);
            }
            $ids[] = (int) $id;
        }
        if (!$ids) return $write();
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $connection = $this->getQuery()->getConnection();
        return $connection->transaction(function () use ($ids, $write) {
            $plan = new Plan();
            $plan->setConnection($this->getModel()->getConnectionName());
            // Shared locks allow unrelated users to bind concurrently, but exclude deletion.
            $found = $plan->newQuery()->whereIn('id', $ids)->orderBy('id')->sharedLock()->pluck('id')->all();
            if (count($found) !== count($ids)) {
                throw ValidationException::withMessages(['plan_id' => '订阅计划不存在']);
            }
            return $write();
        }, 3);
    }

    private function rows(array $values): array
    {
        return $values && is_array(reset($values)) ? $values : [$values];
    }

    public function insert(array $values)
    {
        return $this->withReferences($this->rows($values), fn () => parent::insert($values));
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return $this->withReferences([$values], fn () => parent::insertGetId($values, $sequence));
    }

    public function insertOrIgnore(array $values)
    {
        return $this->withReferences($this->rows($values), fn () => parent::insertOrIgnore($values));
    }

    public function update(array $values)
    {
        return $this->withReferences([$values], fn () => parent::update($values));
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return $this->withReferences($this->rows($values), fn () => parent::upsert($values, $uniqueBy, $update));
    }

    public function insertUsing(array $columns, $query)
    {
        if (in_array('plan_id', $columns, true)) {
            throw ValidationException::withMessages(['plan_id' => '请使用可校验套餐的分批插入方式']);
        }
        return parent::insertUsing($columns, $query);
    }
}
