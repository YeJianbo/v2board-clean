<?php

namespace App\Payments;

use App\Support\PaymentAmount;

class EPay {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '支付类型，如: alipay, wxpay, qqpay',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'money' => $order['total_amount'] / 100,
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => $this->config['pid']
        ];
        if (!empty($this->config['type'])) {
            $params['type'] = $this->config['type'];
        }
        $signature = $this->signature($params);
        if ($signature === null) abort(500, 'Invalid payment signing configuration');
        $params['sign'] = $signature;
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'] ?? null;
        $generateSignature = $this->signature($params);
        if (!is_string($sign) || $generateSignature === null || !hash_equals($generateSignature, $sign)) {
            return false;
        }
        $pid = $params['pid'] ?? null;
        $expectedPid = $this->config['pid'] ?? null;
        if ((!is_string($pid) && !is_int($pid)) || (!is_string($expectedPid) && !is_int($expectedPid))
            || (string) $expectedPid === '' || (string) $pid !== (string) $expectedPid) return false;

        // 强制要求交易状态为成功，避免未支付/处理中状态被误入账
        $tradeStatus = $params['trade_status'] ?? '';
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return('fail');
        }
        $amount = PaymentAmount::fromDecimal($params['money'] ?? null);
        if ($amount === null) return false;

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no'],
            'paid_amount' => $amount,
        ];
    }

    private function signature(array $params): ?string
    {
        $secret = $this->config['key'] ?? null;
        if (!is_string($secret) || $secret === '') return null;
        unset($params['sign'], $params['sign_type']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) continue;
            if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
            // Values are already decoded; preserve literals and retain numeric zero.
            $pairs[] = $key . '=' . $value;
        }
        return md5(implode('&', $pairs) . $secret);
    }
}
