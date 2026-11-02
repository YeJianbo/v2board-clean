<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use App\Services\BatchSortService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $query = Notice::query();
        if (Schema::hasColumn((new Notice())->getTable(), 'sort')) {
            $query->orderByRaw('sort IS NULL, sort ASC');
        }

        return $this->success($query->orderBy('id', 'DESC')->get());
    }

    public function save(NoticeSave $request)
    {
        $data = $request->only([
            'title',
            'content',
            'img_url',
            'tags',
            'show',
            'popup',
            'sort'
        ]);
        try {
            $table = (new Notice())->getTable();
            $columns = array_map('strtolower', Schema::getColumnListing($table));
            foreach (['popup', 'sort'] as $optionalColumn) {
                if (!in_array($optionalColumn, $columns, true)) unset($data[$optionalColumn]);
            }
            $result = DB::transaction(static function () use ($request, $data) {
                if (!$request->input('id')) {
                    $notice = new Notice($data);
                } else {
                    $notice = Notice::whereKey($request->input('id'))->lockForUpdate()->first();
                    if (!$notice) return null;
                    $notice->fill($data);
                }
                if (!$notice->save()) throw new \RuntimeException('Notice save rejected.');
                return true;
            }, 3);
            if ($result === null) return $this->fail([400202, '公告不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $exception);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }



    public function show(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1', 'show' => 'sometimes|boolean']);
        try {
            $result = DB::transaction(static function () use ($params) {
                $notice = Notice::whereKey($params['id'])->lockForUpdate()->first();
                if (!$notice) return null;
                $notice->show = array_key_exists('show', $params) ? (bool) $params['show'] : !$notice->show;
                if (!$notice->save()) throw new \RuntimeException('Notice visibility save failed.');
                return true;
            }, 3);
            if ($result === null) return $this->fail([400202, '公告不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('show', $exception);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        try {
            $result = DB::transaction(static function () use ($params) {
                $notice = Notice::whereKey($params['id'])->lockForUpdate()->first();
                if (!$notice) return null;
                if (!$notice->delete()) throw new \RuntimeException('Notice delete rejected.');
                return true;
            }, 3);
            if ($result === null) return $this->fail([400202, '公告不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', $exception);
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }

    private function logWriteFailure(string $action, \Throwable $exception): void
    {
        \Log::warning('Notice write failed', ['action' => $action, 'exception_type' => get_class($exception), 'exception' => $exception]);
    }

    public function sort(Request $request)
    {
        $sorter = app(BatchSortService::class);
        $ids = $sorter->validatedIds($request);

        if (!Schema::hasColumn((new Notice())->getTable(), 'sort')) {
            return $this->fail([503, '公告排序字段尚未初始化，请先执行数据库迁移']);
        }

        try {
            $sorter->save(new Notice(), $ids);
            return $this->success(true);
        } catch (\Throwable $e) {
            \Log::error($e);
            return $this->fail([500, '排序保存失败']);
        }
    }
}
