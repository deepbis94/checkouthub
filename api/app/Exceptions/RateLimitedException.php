<?php

namespace App\Exceptions;

use RuntimeException;

class RateLimitedException extends RuntimeException
{
    public function __construct(string $bucket)
    {
        parent::__construct("Rate limit exceeded for {$bucket}.");
    }
}
