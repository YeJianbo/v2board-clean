<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Knowledge extends Model
{
    protected $table = 'v2_knowledge';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public static function categoryNames(?Builder $query = null, bool $sortByArticle = false): array
    {
        $model = new static();
        $query ??= $model->newQuery();
        $connection = $query->getConnection();
        $column = $connection->getQueryGrammar()->wrap('category');
        // Category keys are case-sensitive even under MySQL's default collation.
        $expression = match ($connection->getDriverName()) {
            'mysql' => 'BINARY ' . $column,
            'sqlite' => $column . ' COLLATE BINARY',
            default => $column,
        };
        $query->selectRaw($expression . ' as category')->groupByRaw($expression);
        if ($sortByArticle) {
            // Any null-sorted article puts its category first; MIN(sort) alone ignores nulls.
            $sort = $connection->getQueryGrammar()->wrap('sort');
            $query->orderByRaw('MIN(CASE WHEN ' . $sort . ' IS NULL THEN 0 ELSE 1 END) ASC')
                ->orderByRaw('MIN(' . $sort . ') ASC');
        }
        $names = $query->orderByRaw('MIN(id) ASC')->pluck('category');
        $categories = [];
        foreach ($names as $name) $categories[$name] = true;
        return array_keys($categories);
    }
}
