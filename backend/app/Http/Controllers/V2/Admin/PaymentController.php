<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\BatchSortService;
use App\Services\PaymentService;
use App\Services\PaymentMutationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function getPaymentMethods()
    {
        $methods = [];

        $pluginMethods = PaymentService::getAllPaymentMethodNames();
        $methods = array_merge($methods, $pluginMethods);

        return $this->success(array_unique($methods));
    }

    public function fetch(Request $request)
    {
        if ($request->has('id')) {
            $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
            $payment = Payment::find($params['id']);
            if (!$payment) return $this->fail([404, '支付方式不存在']);
            return $this->success($payment)->header('Cache-Control', 'no-store');
        }
        $params = $request->validate(['summary' => 'sometimes|boolean']);
        // Older open tabs still require config in their list until they reload.
        $columns = !empty($params['summary']) ? [
            'id', 'uuid', 'payment', 'name', 'icon', 'notify_domain',
            'handling_fee_fixed', 'handling_fee_percent', 'enable', 'sort', 'created_at', 'updated_at',
        ] : ['*'];
        $payments = Payment::orderBy('sort', 'ASC')->get($columns);
        foreach ($payments as $k => $v) {
            $notifyUrl = url("/api/v1/guest/payment/notify/{$v->payment}/{$v->uuid}");
            if ($v->notify_domain) {
                $parseUrl = parse_url($notifyUrl);
                $notifyUrl = $v->notify_domain . $parseUrl['path'];
            }
            $payments[$k]['notify_url'] = $notifyUrl;
        }
        return $this->success($payments);
    }

    public function getPaymentForm(Request $request)
    {
        try {
            $paymentService = new PaymentService($request->input('payment'), $request->input('id'), null, true);
            return $this->success(collect($paymentService->form()));
        } catch (\Exception $e) {
            return $this->fail([400, '支付方式不存在或未启用']);
        }
    }

    public function show(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(PaymentMutationService::class)->toggle((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            return $this->fail([400202, '支付方式不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('show', (int) $params['id'], $exception);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function save(Request $request)
    {
        if (!admin_setting('app_url')) {
            return $this->fail([400, '请在站点配置中配置站点地址']);
        }
        $params = $request->validate([
            'id' => 'nullable|integer|min:0|max:2147483647',
            'name' => 'required',
            'icon' => 'nullable',
            'payment' => 'required',
            'config' => 'required',
            'enable' => 'nullable|boolean',
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
            return $this->fail([400202, '支付方式不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('save', $id, $exception);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1|max:2147483647']);
        try {
            app(PaymentMutationService::class)->drop((int) $params['id']);
        } catch (ModelNotFoundException $exception) {
            return $this->fail([400202, '支付方式不存在']);
        } catch (\Throwable $exception) {
            $this->logWriteFailure('drop', (int) $params['id'], $exception);
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }


    public function sort(Request $request)
    {
        $sorter = app(BatchSortService::class);
        $ids = $sorter->validatedIds($request);
        try {
            $sorter->save(new Payment(), $ids);
        } catch (\Throwable $e) {
            $this->logWriteFailure('sort', null, $e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    private function logWriteFailure(string $action, ?int $id, \Throwable $exception): void
    {
        Log::warning('Payment configuration write failed', ['action' => $action, 'payment_id' => $id, 'exception' => $exception]);
    }
}
