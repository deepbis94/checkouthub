<?php

namespace App\Exceptions;

use RuntimeException;

class HardGatewayException extends RuntimeException
{
    public function __construct(
        public readonly string $gateway,
        string $message,
        public readonly int $latencyMs = 0,
        public readonly string $reason = 'hard_failure',
        public readonly string $requestHash = '',
        public readonly string $responseHash = '',
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
