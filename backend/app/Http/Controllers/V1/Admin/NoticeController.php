<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoticeController extends Controller
{
    public function update(NoticeSave $request)
    {
        return $this->save($request);
    }

    public function fetch(Request $request)
    {
        return response([
            'data' => Notice::orderBy('id', 'DESC')->get()
        ]);
    }

    public function save(NoticeSave $request)
    {
        $data = $request->only([
            'title',
            'content',
            'img_url',
            'tags'
        ]);
        try {
            $result = DB::transaction(static function () use ($request, $data) {
                if (!$request->input('id')) {
                    $notice = new Notice($data);
                } else {
                    $notice = Notice::whereKey($request->input('id'))->lockForUpdate()->first();
                    if (!$notice) return null;
                    $notice->fill($data);
                }
                if (!$notice->save() || !$notice->exists || !$notice->getKey()) {
                    throw new \RuntimeException('Notice save rejected.');
                }
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $exception);
            abort(500, '保存失败');
        }
        if ($result === null) abort(500, '公告不存在');
        return response([
            'data' => true
        ]);
    }



    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $params = $request->validate(['id' => 'required|integer|min:1']);
        try {
            $result = DB::transaction(static function () use ($params) {
                $notice = Notice::whereKey($params['id'])->lockForUpdate()->first();
                if (!$notice) return null;
                $notice->show = $notice->show ? 0 : 1;
                if (!$notice->save()) throw new \RuntimeException('Notice visibility save rejected.');
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('show', $exception);
            abort(500, '保存失败');
        }
        if ($result === null) abort(500, '公告不存在');

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $params = $request->validate(['id' => 'required|integer|min:1']);
        try {
            $result = DB::transaction(static function () use ($params) {
                $notice = Notice::whereKey($params['id'])->lockForUpdate()->first();
                if (!$notice) return null;
                if (!$notice->delete()) throw new \RuntimeException('Notice delete rejected.');
                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', $exception);
            abort(500, '删除失败');
        }
        if ($result === null) abort(500, '公告不存在');
        return response([
            'data' => true
        ]);
    }

    private function logWriteFailure(string $action, \Throwable $exception): void
    {
        \Log::warning('Notice write failed', ['action' => $action, 'exception_type' => get_class($exception), 'exception' => $exception]);
    }
}
