<?php

namespace App\Exceptions;

use RuntimeException;

class AmbiguousChargeException extends RuntimeException
{
    public function __construct(
        public readonly string $checkoutId,
        public readonly string $gateway,
        public readonly string $chargeRef,
        string $message = 'Charge outcome is ambiguous; checkout parked for review.',
    ) {
        parent::__construct($message);
    }
}
