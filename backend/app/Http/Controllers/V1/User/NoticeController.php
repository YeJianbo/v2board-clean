<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'id' => 'sometimes|integer|min:1|max:2147483647',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:100',
        ]);
        if ($request->has('id')) {
            $id = $request->input('id');
            $notice = Notice::where('id', $id)
                ->where('show', 1)
                ->first();
    
            if (!$notice) {
                return response([
                    'message' => 'Notice not found'
                ], 404);
            }
    
            return response([
                'data' => $notice
            ]);
        }
    
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 5);
    
        $model = Notice::where('show', 1);
        $total = $model->count();
        if (($current - 1) * $pageSize >= $total) {
            return response(['data' => [], 'total' => $total]);
        }
        if (Schema::hasColumn((new Notice())->getTable(), 'sort')) {
            $model->orderByRaw('sort IS NULL, sort ASC');
        }
        $model->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
    
        $res = $model->forPage($current, $pageSize)->get();
    
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

}
