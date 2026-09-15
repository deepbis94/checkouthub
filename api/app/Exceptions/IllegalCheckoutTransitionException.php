<?php

namespace App\Exceptions;

use RuntimeException;

class IllegalCheckoutTransitionException extends RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Illegal checkout transition {$from} -> {$to}.");
    }
}
