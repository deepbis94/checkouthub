<?php

namespace App\Domain\Pricing;

final readonly class PricingQuote
{
    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public string $currency,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $prorationMinor,
        public int $recurringMinor,
        public int $dueNowMinor,
        public int $trialDays,
        public array $lineItems,
        public array $snapshot,
    ) {}
}
