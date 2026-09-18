<?php

use App\Domain\Pricing\PricingEngine;
use App\Models\DiscountCode;
use App\Models\Offer;
use App\Models\Store;
use App\Models\UpsellRule;

beforeEach(function () {
    flushTestRedis();
});

it('computes trial due-now as zero and recurring as plan plus upsells', function () {
    $store = Store::factory()->create(['currency' => 'USD']);
    $offer = Offer::factory()->create([
        'store_id' => $store->id,
        'amount_minor' => 1999,
        'currency' => 'USD',
        'trial_days' => 7,
    ]);
    UpsellRule::query()->create([
        'offer_id' => $offer->id,
        'name' => 'Warranty',
        'trigger_conditions' => ['on' => 'checkout_created'],
        'eligibility_rules' => ['min_total_minor' => 1000],
        'priority' => 1,
        'display_slot' => 'post_purchase',
        'amount_minor' => 499,
        'is_stackable' => true,
        'is_active' => true,
    ]);

    $quote = app(PricingEngine::class)->quote($store, $offer->load('upsellRules'));

    expect($quote->dueNowMinor)->toBe(0)
        ->and($quote->recurringMinor)->toBe(2498)
        ->and($quote->subtotalMinor)->toBe(2498);
});

it('applies percent discounts and proration against due now', function () {
    $store = Store::factory()->create(['currency' => 'USD']);
    $offer = Offer::factory()->create([
        'store_id' => $store->id,
        'amount_minor' => 3000,
        'currency' => 'USD',
        'trial_days' => 0,
        'interval' => 'month',
    ]);
    DiscountCode::factory()->create([
        'store_id' => $store->id,
        'code' => 'HALF',
        'type' => 'percent',
        'value' => 50,
    ]);

    $quote = app(PricingEngine::class)->quote(
        $store,
        $offer->load('upsellRules'),
        [],
        'HALF',
        ['proration_days_remaining' => 15, 'proration_period_days' => 30],
    );

    expect($quote->discountMinor)->toBe(1500)
        ->and($quote->prorationMinor)->toBe(1500)
        ->and($quote->recurringMinor)->toBe(1500)
        ->and($quote->dueNowMinor)->toBe(0);
});
