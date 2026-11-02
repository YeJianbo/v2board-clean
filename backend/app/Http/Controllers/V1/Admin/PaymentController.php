<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\BatchSortService;
use App\Services\PaymentMutationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function getPaymentMethods()
    {
        $methods = [];
        foreach (glob(base_path('app//Payments') . '/*.php') as $file) {
            array_push($methods, pathinfo($file)['filename']);
        }
        return response([
            'data' => $methods
        ]);
    }

    public function fetch()
    {
        $payments = Payment::orderBy('sort', 'ASC')->get();
        foreach ($payments as $k => $v) {
            $notifyUrl = url("/api/v1/guest/payment/notify/{$v->payment}/{$v->uuid}");
            if ($v->notify_domain) {
                $parseUrl = parse_url($notifyUrl);
                $notifyUrl = $v->notify_domain . $parseUrl['path'];
            }
            $payments[$k]['notify_url'] = $notifyUrl;
        }
        return response([
            'data' => $payments
        ]);
    }

    public function getPaymentForm(Request $request)
    {
        $paymentService = new PaymentService($request->input('payment'), $request->input('id'), null, true);
        return response([
            'data' => $paymentService->form()
        ]);
    }

    public function show(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(PaymentMutationService::class)->toggle((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            abort(500, '支付方式不存在');
        } catch (\Throwable $exception) {
            $this->logWriteFailure('show', (int) $params['id'], $exception);
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function save(Request $request)
    {
        if (!config('v2board.app_url')) {
            abort(500, '请在站点配置中配置站点地址');
        }
        $params = $request->validate([
            'id' => 'nullable|integer|min:0|max:2147483647',
            'name' => 'required',
            'icon' => 'nullable',
            'payment' => 'required',
            'config' => 'required',
            'notify_domain' => 'nullable|url',
            'handling_fee_fixed' => 'nullable|integer|between:0,2147483647',
            'handling_fee_percent' => 'nullable|numeric|between:0,100'
        ], [
            'name.required' => '显示名称不能为空',
            'payment.required' => '网关参数不能为空',
            'config.required' => '配置参数不能为空',
            'notify_domain.url' => '自定义通知域名格式有误',
            'handling_fee_fixed.integer' => '固定手续费格式有误',
            'handling_fee_fixed.between' => '固定手续费须为0-2147483647之间的整数',
            'handling_fee_percent.between' => '百分比手续费范围须在0-100之间'
        ]);
        $id = (int) ($params['id'] ?? 0) ?: null;
        try {
            app(PaymentMutationService::class)->save($params, $id);
        } catch (ModelNotFoundException $exception) {
            abort(500, '支付方式不存在');
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $id, $exception);
            abort(500, $id ? $exception->getMessage() : '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(PaymentMutationService::class)->drop((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            abort(500, '支付方式不存在');
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', (int) $params['id'], $exception);
            abort(500, '删除失败');
        }
        return response(['data' => true]);
    }


    public function sort(Request $request)
    {
        $sorter = app(BatchSortService::class);
        $ids = $sorter->validatedIds($request);
        try {
            $sorter->save(new Payment(), $ids);
        } catch (\Throwable $error) {
            $this->logWriteFailure('sort', null, $error);
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    private function logWriteFailure(string $action, ?int $id, \Throwable $exception): void
    {
        Log::warning('Payment configuration write failed', ['action' => $action, 'payment_id' => $id, 'exception' => $exception]);
    }
}
