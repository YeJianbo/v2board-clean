<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class BatchSortService
{
    public function validatedIds(Request $request, string $field = 'ids'): array
    {
        $params = $request->validate([$field => ['bail', 'required', 'array', 'max:10000', function ($attribute, $ids, $fail) {
            $seen = [];
            foreach ($ids as $value) {
                if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false
                    || (int) $value < 1 || isset($seen[(int) $value])) {
                    $fail('排序 ID 必须是互不重复的正整数');
                    return;
                }
                $seen[(int) $value] = true;
            }
        }]], [$field . '.required' => '参数有误', $field . '.array' => '参数有误']);
        return array_map('intval', array_values($params[$field]));
    }

    public function save(Model $model, array $ids, bool $touchUpdatedAt = true): void
    {
        $connection = $model->getConnection();
        $connection->transaction(function () use ($model, $ids, $touchUpdatedAt, $connection) {
            $key = $model->getKeyName();
            // Validate and lock every requested row before writing any rank.
            $existing = $model->newQuery()->whereIn($key, $ids)->orderBy($key)->lockForUpdate()->pluck($key)->all();
            if (count($existing) !== count($ids)) {
                throw (new ModelNotFoundException())->setModel(get_class($model), array_diff($ids, $existing));
            }
            $grammar = $connection->getQueryGrammar();
            $table = $grammar->wrapTable($model->getTable());
            $column = $grammar->wrap($key);
            $timestamp = $touchUpdatedAt && $model->usesTimestamps() ? $model->freshTimestampString() : null;
            foreach (array_chunk($ids, 300) as $batch => $chunk) {
                $cases = [];
                $bindings = [];
                foreach ($chunk as $index => $id) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $id;
                    $bindings[] = $batch * 300 + $index + 1;
                }
                $sql = "UPDATE {$table} SET " . $grammar->wrap('sort') . " = CASE {$column} " . implode(' ', $cases) . ' END';
                if ($timestamp !== null) {
                    $sql .= ', ' . $grammar->wrap($model->getUpdatedAtColumn()) . ' = ?';
                    $bindings[] = $timestamp;
                }
                $sql .= " WHERE {$column} IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')';
                $connection->update($sql, array_merge($bindings, $chunk));
            }
        }, 3);
    }
}
