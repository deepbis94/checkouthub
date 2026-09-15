<?php

namespace App\Services;

use App\Enums\CheckoutState;
use App\Models\Checkout;
use App\Models\CheckoutLineItem;
use App\Models\Offer;
use App\Models\Store;
use App\Domain\Checkout\CheckoutStateMachine;
use App\Domain\Pricing\PricingEngine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CheckoutService
{
    public function __construct(
        private readonly PricingEngine $pricing,
        private readonly CheckoutStateMachine $states,
        private readonly CheckoutIdempotency $idempotency,
        private readonly TokenBucket $tokenBucket,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Store $store, array $input, ?string $idempotencyKey): Checkout
    {
        $this->assertStoreRateLimit($store);

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = $this->idempotency->find($store, $idempotencyKey);
            if ($existing) {
                return $existing->load(['lineItems', 'transitions', 'offer']);
            }
        }

        $offer = Offer::query()
            ->where('store_id', $store->id)
            ->whereKey($input['offer_id'])
            ->where('is_active', true)
            ->with('upsellRules')
            ->firstOrFail();

        try {
            $quote = $this->pricing->quote(
                $store,
                $offer,
                array_map('intval', $input['upsell_ids'] ?? []),
                $input['discount_code'] ?? null,
                [
                    'customer_email' => $input['customer_email'] ?? null,
                    'proration_days_remaining' => (int) ($input['proration_days_remaining'] ?? 0),
                    'proration_period_days' => (int) ($input['proration_period_days'] ?? 0),
                ],
            );
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $ttl = (int) ($input['expires_in'] ?? config('checkouthub.checkout_ttl_seconds', 1800));
        $ttl = max(60, min(86400, $ttl));

        try {
            $checkout = DB::transaction(function () use ($store, $offer, $input, $quote, $ttl, $idempotencyKey) {
                $checkout = Checkout::query()->create([
                    'store_id' => $store->id,
                    'offer_id' => $offer->id,
                    'state' => CheckoutState::Pending,
                    'offer_snapshot' => $quote->snapshot,
                    'currency' => $quote->currency,
                    'subtotal_minor' => $quote->subtotalMinor,
                    'discount_minor' => $quote->discountMinor,
                    'total_minor' => $quote->dueNowMinor,
                    'discount_code' => $input['discount_code'] ?? null,
                    'customer_email' => $input['customer_email'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => now()->addSeconds($ttl),
                ]);

                foreach ($quote->lineItems as $item) {
                    CheckoutLineItem::query()->create([
                        'checkout_id' => $checkout->id,
                        'sku' => $item['sku'],
                        'name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_amount_minor' => $item['unit_amount_minor'],
                        'amount_minor' => $item['amount_minor'],
                        'type' => $item['type'],
                        'meta' => $item['meta'] ?? [],
                    ]);
                }

                $this->states->recordCreated($checkout, 'api');

                return $checkout;
            });
        } catch (QueryException $e) {
            if (is_string($idempotencyKey) && $idempotencyKey !== '' && $this->isUniqueViolation($e)) {
                return $this->idempotency->find($store, $idempotencyKey)?->load(['lineItems', 'transitions', 'offer'])
                    ?? throw $e;
            }

            throw $e;
        }

        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $this->idempotency->remember($store, $idempotencyKey, $checkout, $ttl);
        }

        return $checkout->load(['lineItems', 'transitions', 'offer']);
    }

    public function show(Store $store, string $id): Checkout
    {
        return Checkout::query()
            ->where('store_id', $store->id)
            ->whereKey($id)
            ->with(['lineItems', 'transitions', 'offer', 'gatewayEvents'])
            ->firstOrFail();
    }

    private function assertStoreRateLimit(Store $store): void
    {
        $limit = config('checkouthub.rate_limits.store');

        if (! $this->tokenBucket->allow(
            "store:{$store->id}",
            (float) $limit['refill_per_second'],
            (int) $limit['capacity'],
        )) {
            abort(429, 'Store rate limit exceeded.');
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';

        return $sqlState === '23000' || str_contains($e->getMessage(), 'UNIQUE');
    }
}
