<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PaymentReceiptService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $parameters = $request->input();
            $verify = $paymentService->notify($parameters);
            // Some signed gateway events are acknowledged without a settled payment.
            if ($verify === 'success') {
                $this->archive($paymentService, $uuid, $request, $parameters, ['acknowledged_non_settlement' => true]);
                return 'success';
            }
            if (!is_array($verify) || !$this->validReference($verify['trade_no'] ?? null)
                || !$this->validReference($verify['callback_no'] ?? null)) abort(500, 'verify error');
            $paidAmount = $verify['paid_amount'] ?? null;
            if (array_key_exists('paid_amount', $verify) && (!is_int($paidAmount) || $paidAmount < 0)) abort(500, 'verify error');
            $this->archive($paymentService, $uuid, $request, $parameters, array_intersect_key($verify, array_flip(['trade_no', 'callback_no', 'paid_amount'])));
            if (!$this->handle($verify['trade_no'], $verify['callback_no'], $paymentService->paymentId(), $paidAmount)) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Throwable $e) {
            Log::error('支付回调处理失败', [
                'payment_method' => $method,
                'payment_uuid' => $uuid,
                'exception' => $e,
            ]);
            abort(500, 'fail');
        }
    }

    private function validReference($value): bool
    {
        return (is_string($value) || is_int($value)) && (string) $value !== ''
            && strlen((string) $value) <= 255;
    }

    private function archive(PaymentService $payment, string $uuid, Request $request, array $parameters, array $verified): void
    {
        try {
            app(PaymentReceiptService::class)->record($payment, $uuid, $request, $parameters, $verified);
        } catch (\Throwable $exception) {
            // Archive and logging availability must not change a gateway's payment acknowledgement.
            try {
                Log::error('支付回调原件归档失败', [
                    'payment_id' => $payment->paymentId(),
                    'trade_no' => $verified['trade_no'] ?? null,
                    'exception' => $exception,
                ]);
            } catch (\Throwable $loggingFailure) {
                error_log('Payment receipt persistence and diagnostic logging failed.');
            }
        }
    }

    private function handle(string $tradeNo, string $callbackNo, int $paymentId, ?int $paidAmount)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo, false, $paymentId, $paidAmount)) {
            if ((int) $orderService->order->status === 2) {
                Log::warning('已取消订单收到支付回调，需要人工核对', ['order_id' => $order->id]);
            }
            return false;
        }
        if (!$orderService->didMarkPaid()) return true;
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        try {
            app(TelegramService::class)->sendMessageWithAdmin($message);
        } catch (\Throwable $exception) {
            Log::warning('收款通知派发失败，支付状态已确认', [
                'order_id' => $order->id,
                'exception_type' => get_class($exception),
                'exception' => $exception,
            ]);
        }
        return true;
    }
}
