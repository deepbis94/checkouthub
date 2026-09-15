<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidWebhookSignatureException extends RuntimeException
{
    public function __construct(string $provider = 'webhook')
    {
        parent::__construct("Invalid {$provider} webhook signature.");
    }
}
