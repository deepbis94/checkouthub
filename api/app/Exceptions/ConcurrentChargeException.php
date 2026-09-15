<?php

namespace App\Exceptions;

use RuntimeException;

class ConcurrentChargeException extends RuntimeException
{
    public function __construct(string $checkoutId)
    {
        parent::__construct("Checkout {$checkoutId} is already being charged.");
    }
}
