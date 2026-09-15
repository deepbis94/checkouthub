<?php

namespace App\Gateways;

final readonly class ProbeResult
{
    public function __construct(
        public bool $ok,
        public string $gateway,
        public int $latencyMs,
        public ?string $message = null,
    ) {}
}
