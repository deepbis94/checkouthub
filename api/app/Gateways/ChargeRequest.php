<?php

namespace App\Gateways;

final readonly class ChargeRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $checkoutId,
        public int $storeId,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
        public ?string $customerEmail = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toHashableArray(): array
    {
        return [
            'checkout_id' => $this->checkoutId,
            'store_id' => $this->storeId,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'idempotency_key' => $this->idempotencyKey,
            'customer_email' => $this->customerEmail,
        ];
    }
}
