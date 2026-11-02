<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ApiException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string|int $messageOrCode, ?string $message = null)
    {
        $code = is_int($messageOrCode) ? $messageOrCode : 400;
        $status = (int) substr((string) $code, 0, 3);
        $this->httpStatus = $status >= 400 && $status <= 599 ? $status : 400;
        parent::__construct($message ?? (string) $messageOrCode, $code);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'message' => $this->getMessage(),
            'data' => null,
            'error' => null,
        ], $this->httpStatus);
    }
}
