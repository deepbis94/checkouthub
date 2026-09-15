<?php

namespace App\Gateways;

final readonly class ChargeResult
{
    public function __construct(
        public bool $success,
        public string $gateway,
        public ?string $chargeId,
        public int $latencyMs,
        public string $requestHash,
        public string $responseHash,
        public string $outcome,
        public ?string $failoverReason = null,
        public ?string $errorCode = null,
        public ?string $message = null,
    ) {}
}
