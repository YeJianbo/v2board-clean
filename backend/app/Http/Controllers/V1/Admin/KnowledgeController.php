<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Http\Requests\Admin\KnowledgeSort;
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
            if (!$knowledge) abort(500, '知识不存在');
            return response([
                'data' => $knowledge->toArray()
            ]);
        }
        return response([
            'data' => Knowledge::select(['title', 'id', 'updated_at', 'category', 'show'])
                ->orderBy('sort', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
        ]);
    }

    public function getCategory(Request $request)
    {
        return response([
            'data' => Knowledge::categoryNames()
        ]);
    }

    public function save(KnowledgeSave $request)
    {
        try {
            app(KnowledgeMutationService::class)->save($request->validated(), (int) $request->input('id') ?: null);
        } catch (ModelNotFoundException $exception) {
            abort(500, '知识不存在');
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $exception);
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function show(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647', 'show' => 'sometimes|boolean']);
        try {
            app(KnowledgeMutationService::class)->show((int) $params['id'], array_key_exists('show', $params) ? (bool) $params['show'] : null);
        } catch (ModelNotFoundException $exception) {
            abort(500, '知识不存在');
        } catch (\Throwable $exception) {
            $this->logWriteFailure('show', $exception);
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function sort(KnowledgeSort $request)
    {
        $request->merge(['ids' => $request->input('knowledge_ids')]);
        $sorter = app(BatchSortService::class);
        $ids = $sorter->validatedIds($request);
        try {
            $sorter->save(new Knowledge(), $ids, false);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('sort', $exception);
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(KnowledgeMutationService::class)->drop((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            abort(500, '知识不存在');
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', $exception);
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function logWriteFailure(string $action, \Throwable $exception): void
    {
        \Log::warning('Knowledge write failed', ['action' => $action, 'exception_type' => get_class($exception), 'exception' => $exception]);
    }
}
