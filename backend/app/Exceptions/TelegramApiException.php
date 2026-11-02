<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    public int $retryAfter;

    public function __construct(string $message, int $code = 0, int $retryAfter = 0)
    {
        parent::__construct($message, $code);
        $this->retryAfter = max(0, $retryAfter);
    }

    public function isPermanent(): bool
    {
        return $this->getCode() >= 400 && $this->getCode() < 500 && !in_array($this->getCode(), [408, 429], true);
    }
}
