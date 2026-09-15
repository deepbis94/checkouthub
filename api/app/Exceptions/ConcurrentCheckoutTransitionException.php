<?php

namespace App\Exceptions;

use RuntimeException;

class ConcurrentCheckoutTransitionException extends RuntimeException
{
    public function __construct(string $checkoutId)
    {
        parent::__construct("Checkout {$checkoutId} changed state concurrently.");
    }
}
