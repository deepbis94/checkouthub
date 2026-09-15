<?php

namespace App\Console\Commands;

use App\Services\CircuitBreaker;
use Illuminate\Console\Command;

class CircuitCommand extends Command
{
    protected $signature = 'checkouthub:circuit {gateway} {state : open|closed|reset}';

    protected $description = 'Force a payment gateway circuit breaker state (for failover demos).';

    public function handle(CircuitBreaker $breaker): int
    {
        $gateway = (string) $this->argument('gateway');
        $state = (string) $this->argument('state');

        match ($state) {
            'open' => $breaker->forceOpen($gateway),
            'closed' => $breaker->forceClosed($gateway),
            default => $breaker->reset($gateway),
        };

        $this->info(json_encode($breaker->snapshot($gateway), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
