<?php

namespace App\Services;

use App\Models\Knowledge;
use Illuminate\Support\Facades\DB;

class KnowledgeMutationService
{
    public function save(array $data, ?int $id): void
    {
        unset($data['id']);
        DB::transaction(static function () use ($data, $id): void {
            $article = $id ? Knowledge::whereKey($id)->lockForUpdate()->firstOrFail() : new Knowledge();
            $article->fill($data);
            if (!$article->save() || !$article->exists || !$article->getKey()) {
                throw new \RuntimeException('Knowledge save rejected');
            }
        }, 3);
    }

    public function show(int $id, ?bool $show): void
    {
        DB::transaction(static function () use ($id, $show): void {
            $article = Knowledge::whereKey($id)->lockForUpdate()->firstOrFail();
            $article->show = $show === null ? !(bool) $article->show : $show;
            if (!$article->save()) throw new \RuntimeException('Knowledge visibility save rejected');
        }, 3);
    }

    public function drop(int $id): void
    {
        DB::transaction(static function () use ($id): void {
            $article = Knowledge::whereKey($id)->lockForUpdate()->firstOrFail();
            if (!$article->delete()) throw new \RuntimeException('Knowledge delete rejected');
        }, 3);
    }
}
