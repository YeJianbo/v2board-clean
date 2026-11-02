<?php

namespace App\Payments;

class Coinbase {
    public function __construct($config) {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'coinbase_url' => [
                'label' => '接口地址',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_api_key' => [
                'label' => 'API KEY',
                'description' => '',
                'type' => 'input',
            ],
            'coinbase_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order) {

        $params = [
            'name' => '订阅套餐',
            'description' => '订单号 ' . $order['trade_no'],
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => sprintf('%.2f', $order['total_amount'] / 100),
                'currency' => 'CNY'
            ],
            'metadata' => [
                "outTradeNo" => $order['trade_no'],
            ],
        ];

        $params_string = http_build_query($params);
        
        $ret_raw = self::_curlPost($this->config['coinbase_url'], $params_string);

        $ret = @json_decode($ret_raw, true);
        
        if(empty($ret['data']['hosted_url'])) {
            abort(500, "error!");
        }
        return [
            'type' => 1,
            'data' => $ret['data']['hosted_url'],
        ];
    }

    public function notify($params) {
        $payload = request()->getContent();
        $secret = $this->config['coinbase_webhook_key'] ?? '';
        if (!is_string($secret) || $secret === '') abort(500, 'Webhook secret is not configured');
        $signatureHeader = request()->header('X-CC-Webhook-Signature', '');
        $computedSignature = hash_hmac('sha256', $payload, $secret);
        if (!self::hashEqual($signatureHeader, $computedSignature)) {
            abort(400, 'HMAC signature does not match');
        }
        $event = json_decode($payload, true)['event'] ?? null;
        if (!is_array($event) || !is_string($event['type'] ?? null) || $event['type'] === '') {
            abort(400, 'Invalid webhook event');
        }
        if (!in_array($event['type'], ['charge:confirmed', 'charge:resolved'], true)) return 'success';
        $timeline = $event['data']['timeline'] ?? null;
        $last = is_array($timeline) && count($timeline) > 0 ? end($timeline) : null;
        $expectedStatus = $event['type'] === 'charge:confirmed' ? 'COMPLETED' : 'RESOLVED';
        if (!is_array($last) || ($last['status'] ?? null) !== $expectedStatus) {
            abort(400, 'Charge is not settled');
        }
        $tradeNo = $event['data']['metadata']['outTradeNo'] ?? null;
        $callbackNo = $event['id'] ?? null;
        foreach ([$tradeNo, $callbackNo] as $reference) {
            if ((!is_string($reference) && !is_int($reference)) || trim((string) $reference) === ''
                || strlen((string) $reference) > 255) {
                abort(400, 'Invalid payment reference');
            }
        }
        return [
            'trade_no' => (string) $tradeNo,
            'callback_no' => (string) $callbackNo,
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
            $ch, CURLOPT_HTTPHEADER, array('X-CC-Api-Key:' .$this->config['coinbase_api_key'], 'X-CC-Version: 2018-03-22')
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
    public function hashEqual($str1, $str2)
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

