<?php

namespace App\Gateways;

final readonly class RefundResult
{
    public function __construct(
        public bool $success,
        public string $gateway,
        public ?string $refundId,
        public int $latencyMs,
        public ?string $message = null,
    ) {}
}
