<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;

class BTCPay {
    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'btcpay_url' => [
                'label' => 'API接口所在网址(包含最后的斜杠)',
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_storeId' => [
                'label' => 'storeId',
                'description' => '',
                'type' => 'input',
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'description' => '个人设置中的API KEY(非商店设置中的)',
                'type' => 'input',
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order) {

        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);

        $ret_raw = self::_curlPost($this->config['btcpay_url'] . 'api/v1/stores/' . $this->config['btcpay_storeId'] . '/invoices', $params_string);

        $ret = @json_decode($ret_raw, true);
        
        if(empty($ret['checkoutLink'])) {
            abort(500, "error!");
        }
        return [
            'type' => 1, // Redirect to url
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params) {
        $payload = request()->getContent();
        $secret = $this->config['btcpay_webhook_key'] ?? '';
        if (!is_string($secret) || $secret === '') abort(500, 'Webhook secret is not configured');
        $signature = request()->header('BTCPay-Sig', '');
        $computedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        if (!self::hashEqual($signature, $computedSignature)) {
            abort(400, 'HMAC signature does not match');
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || !is_string($event['type'] ?? null) || $event['type'] === '') {
            abort(400, 'Invalid webhook event');
        }
        // A settled individual payment may still leave the invoice partially paid.
        if ($event['type'] !== 'InvoiceSettled') return 'success';
        $invoiceId = $event['invoiceId'] ?? null;
        if (!is_string($invoiceId) || trim($invoiceId) === '' || strlen($invoiceId) > 255) {
            abort(400, 'Invalid invoice reference');
        }
        $url = rtrim($this->config['btcpay_url'], '/') . '/api/v1/stores/'
            . rawurlencode($this->config['btcpay_storeId']) . '/invoices/' . rawurlencode($invoiceId);
        $invoice = Http::withHeaders(['Authorization' => 'token ' . $this->config['btcpay_api_key']])
            ->timeout(15)->withOptions(['connect_timeout' => 5, 'allow_redirects' => false])
            ->get($url)->throw()->json();
        if (!is_array($invoice) || ($invoice['id'] ?? null) !== $invoiceId
            || ($invoice['status'] ?? null) !== 'Settled') {
            abort(500, 'Invoice is not settled');
        }
        $tradeNo = $invoice['metadata']['orderId'] ?? null;
        if ((!is_string($tradeNo) && !is_int($tradeNo)) || trim((string) $tradeNo) === ''
            || strlen((string) $tradeNo) > 255) {
            abort(400, 'Invalid order reference');
        }
        return [
            'trade_no' => (string) $tradeNo,
            'callback_no' => $invoiceId,
        ];
    }


    private function _curlPost($url,$params=false){
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array('Authorization:' .'token '.$this->config['btcpay_api_key'], 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }


    /**
     * @param string $str1
     * @param string $str2
     * @return bool
     */
    private function hashEqual($str1, $str2)
    {   

        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
    
}

