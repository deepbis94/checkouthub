<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutExpiredException extends RuntimeException
{
    public function __construct(string $checkoutId)
    {
        parent::__construct("Checkout {$checkoutId} has expired.");
    }
}
