<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PaymentReceiptService
{
    public function record(PaymentService $payment, string $uuid, Request $request, array $parameters, array $verified): void
    {
        $body = $request->getContent();
        $headers = [];
        foreach ($payment->receiptHeaderNames() as $name) {
            $name = strtolower($name);
            if ($request->headers->has($name)) {
                $headers[$name] = array_map('base64_encode', $request->headers->all($name));
            }
        }
        ksort($headers);
        $snapshot = json_encode([
            'version' => 1,
            'payment_id' => $payment->paymentId(),
            'payment_method_base64' => base64_encode($payment->method),
            'payment_uuid_base64' => base64_encode($uuid),
            'http_method' => $request->getMethod(),
            'path_base64' => base64_encode($request->getPathInfo()),
            'query_base64' => base64_encode((string) $request->server->get('QUERY_STRING', '')),
            'body_base64' => base64_encode($body),
            'signature_headers_base64' => $headers,
            'verified_parameters' => $this->encodeValue($parameters),
            'verification' => $this->encodeValue($verified),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $now = time();
        $grammar = DB::connection()->getQueryGrammar();
        $lastSeen = $grammar->wrap('v2_payment_receipt.last_seen_at');
        $seenCount = $grammar->wrap('v2_payment_receipt.seen_count');
        // The digest identifies the plaintext original, not randomized ciphertext or arrival time.
        DB::table('v2_payment_receipt')->upsert([[
            'payment_id' => $payment->paymentId(),
            'trade_no' => isset($verified['trade_no']) ? (string) $verified['trade_no'] : null,
            'callback_no' => isset($verified['callback_no']) ? (string) $verified['callback_no'] : null,
            'fingerprint' => hash('sha256', $snapshot),
            'body_bytes' => strlen($body),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'seen_count' => 1,
            'encrypted_snapshot' => Crypt::encryptString($snapshot),
        ]], ['payment_id', 'fingerprint'], [
            'last_seen_at' => DB::raw('CASE WHEN ' . $lastSeen . ' < ' . $now . ' THEN ' . $now . ' ELSE ' . $lastSeen . ' END'),
            'seen_count' => DB::raw($seenCount . ' + 1'),
        ]);
    }

    public function decrypt(string $ciphertext, string $fingerprint): array
    {
        $plaintext = Crypt::decryptString($ciphertext);
        if (!hash_equals($fingerprint, hash('sha256', $plaintext))) {
            throw new \RuntimeException('Payment receipt identity mismatch.');
        }
        return json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
    }

    private function encodeValue($value): array
    {
        // Flat typed nodes preserve deep input, arbitrary bytes and IEEE-754 values without unserialization.
        $pending = [$value];
        $nodes = [];
        for ($index = 0; $index < count($pending); $index++) {
            $value = $pending[$index];
            if (is_array($value)) {
                $items = [];
                foreach ($value as $key => $item) {
                    $keyIndex = count($pending);
                    $pending[] = $key;
                    $valueIndex = count($pending);
                    $pending[] = $item;
                    $items[] = ['key' => $keyIndex, 'value' => $valueIndex];
                }
                $nodes[] = ['type' => 'array', 'items' => $items];
            } elseif (is_string($value)) {
                $nodes[] = ['type' => 'string', 'base64' => base64_encode($value)];
            } elseif (is_float($value)) {
                $nodes[] = ['type' => 'double', 'ieee754_be_base64' => base64_encode(pack('E', $value))];
            } else {
                $nodes[] = ['type' => gettype($value), 'value' => $value];
            }
        }
        return ['root' => 0, 'nodes' => $nodes];
    }
}
