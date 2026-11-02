<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareApiClient
{
    protected string $apiToken;

    public function __construct(string $apiToken)
    {
        $this->apiToken = trim($apiToken);
        if ($this->apiToken === '' || preg_match('/[\r\n]/', $this->apiToken)) {
            throw new RuntimeException('Cloudflare API Token 无效');
        }
    }

    public function request(string $method, string $path, array $data = [], string $failure = 'Cloudflare 请求失败'): array
    {
        if (!preg_match('#^/(zones|accounts|user/tokens)(/|$)#', $path)
            || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new RuntimeException('Cloudflare API 路径或方法无效');
        }
        try {
            $client = Http::baseUrl('https://api.cloudflare.com/client/v4')
                ->timeout(8)->acceptJson()->withToken($this->apiToken)
                ->withOptions(['allow_redirects' => false]);
            $options = $method === 'GET' ? ['query' => $data] : ['json' => $data];
            $response = $client->send($method, $path, $options);
            $payload = $response->json();
        } catch (\Throwable $exception) {
            // Do not retain an unredacted transport exception in the exception chain.
            throw new RuntimeException($failure . '：' . $this->redactToken($exception->getMessage()));
        }
        if (!$response->successful() || !is_array($payload) || !($payload['success'] ?? false)) {
            $message = 'HTTP ' . $response->status();
            $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
            $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
            foreach (array_merge($errors, $messages) as $item) {
                $candidate = is_array($item) ? ($item['message'] ?? '') : $item;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $message = trim($candidate);
                    break;
                }
            }
            throw new RuntimeException($failure . '：' . $this->redactToken($message));
        }
        return $payload;
    }

    protected function redactToken(string $message): string
    {
        return str_replace($this->apiToken, '[redacted]', $message);
    }
}
