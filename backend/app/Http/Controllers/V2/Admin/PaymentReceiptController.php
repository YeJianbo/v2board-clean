<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentReceiptController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'trade_no' => 'sometimes|string|max:255',
            'payment_id' => 'sometimes|integer|min:1|max:2147483647',
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = DB::table('v2_payment_receipt');
        foreach (['trade_no', 'payment_id'] as $field) {
            if (array_key_exists($field, $params)) $query->where($field, $params[$field]);
        }
        try {
            $page = $query->orderByDesc('id')->paginate(
                $params['pageSize'] ?? 20,
                ['id', 'payment_id', 'trade_no', 'callback_no', 'body_bytes', 'first_seen_at', 'last_seen_at', 'seen_count'],
                'current', $params['current'] ?? 1
            );
            foreach ($page->items() as $receipt) {
                foreach (['trade_no', 'callback_no'] as $field) {
                    if ($receipt->$field !== null && preg_match('//u', $receipt->$field) !== 1) {
                        $receipt->{$field . '_base64'} = base64_encode($receipt->$field);
                        $receipt->$field = null;
                    }
                }
            }
            return $this->paginate($page)->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $exception) {
            Log::error('支付回调原件列表读取失败', ['exception' => $exception]);
            return $this->fail([503, '原件归档暂不可用'])->header('Cache-Control', 'no-store, private');
        }
    }

    public function download(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1', 'trade_no' => 'sometimes|string|max:255']);
        $receipt = DB::table('v2_payment_receipt')->where('id', $params['id'])->first();
        if (!$receipt || (isset($params['trade_no']) && !hash_equals((string) $receipt->trade_no, $params['trade_no']))) {
            return $this->fail([404, '原件不存在']);
        }
        try {
            $snapshot = app(PaymentReceiptService::class)->decrypt($receipt->encrypted_snapshot, $receipt->fingerprint);
            $body = base64_decode($snapshot['body_base64'], true);
            if ($body === false || strlen($body) !== (int) $receipt->body_bytes) {
                throw new \RuntimeException('Payment receipt body length mismatch.');
            }
        } catch (\Throwable $exception) {
            Log::error('支付回调原件读取失败', ['receipt_id' => $receipt->id, 'exception' => $exception]);
            return $this->fail([500, '原件无法读取或解密']);
        }
        return response()->json(['receipt_id' => $receipt->id, 'first_seen_at' => $receipt->first_seen_at,
            'last_seen_at' => $receipt->last_seen_at, 'seen_count' => $receipt->seen_count, 'snapshot' => $snapshot], 200, [
            'Content-Disposition' => 'attachment; filename="payment-receipt-' . $receipt->id . '.json"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_PRESERVE_ZERO_FRACTION);
    }
}
