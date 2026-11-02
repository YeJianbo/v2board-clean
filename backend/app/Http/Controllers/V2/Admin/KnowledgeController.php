<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Models\Knowledge;
use App\Services\BatchSortService;
use App\Services\KnowledgeMutationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate(['id' => 'nullable|integer|min:0|max:2147483647']);
        if ($request->input('id')) {
            $knowledge = Knowledge::find($request->input('id'));
            if (!$knowledge)
                return $this->fail([400202, '知识不存在']);
            return $this->success($knowledge->toArray());
        }
        $data = Knowledge::select(['title', 'id', 'updated_at', 'category', 'language', 'show', 'sort'])
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
        return $this->success($data);
    }

    public function getCategory(Request $request)
    {
        return $this->success(Knowledge::categoryNames());
    }

    public function save(KnowledgeSave $request)
    {
        try {
            app(KnowledgeMutationService::class)->save($request->validated(), (int) $request->input('id') ?: null);
        } catch (ModelNotFoundException $exception) {
            return $this->fail([400202, '知识不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $exception);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function show(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647', 'show' => 'sometimes|boolean']);
        try {
            app(KnowledgeMutationService::class)->show((int) $params['id'], array_key_exists('show', $params) ? (bool) $params['show'] : null);
        } catch (ModelNotFoundException $exception) {
            return $this->fail([400202, '知识不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('show', $exception);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $sorter = app(BatchSortService::class);
        $ids = $sorter->validatedIds($request);
        try {
            $sorter->save(new Knowledge(), $ids, false);
        } catch (ModelNotFoundException $e) {
            return $this->fail([400202, '知识不存在']);
        } catch (\Throwable $e) {
            $this->logWriteFailure('sort', $e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(KnowledgeMutationService::class)->drop((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            return $this->fail([400202, '知识不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', $exception);
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    private function logWriteFailure(string $action, \Throwable $exception): void
    {
        \Log::warning('Knowledge write failed', ['action' => $action, 'exception_type' => get_class($exception), 'exception' => $exception]);
    }
}
