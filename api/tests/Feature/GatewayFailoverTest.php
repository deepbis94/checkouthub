<?php

use App\Exceptions\ConcurrentChargeException;
use App\Gateways\ChargeRequest;
use App\Gateways\GatewayRouter;
use App\Models\Checkout;
use App\Models\GatewayEvent;
use App\Models\Store;
use App\Services\CircuitBreaker;

beforeEach(function () {
    flushTestRedis();
    config([
        'checkouthub.gateways.stripe.mode' => 'simulate',
        'checkouthub.gateways.stripe.simulate_failure' => false,
        'checkouthub.gateways.razorpay.mode' => 'simulate',
        'checkouthub.gateways.razorpay.simulate_failure' => false,
    ]);
});

function chargeRequestFor(Checkout $checkout): ChargeRequest
{
    return new ChargeRequest(
        checkoutId: $checkout->id,
        storeId: $checkout->store_id,
        amountMinor: $checkout->total_minor,
        currency: $checkout->currency,
        idempotencyKey: 'idem_'.$checkout->id,
        customerEmail: $checkout->customer_email,
    );
}

it('charges stripe first when the circuit is closed', function () {
    $checkout = Checkout::factory()->create();

    $result = app(GatewayRouter::class)->charge(chargeRequestFor($checkout));

    expect($result->success)->toBeTrue()
        ->and($result->gateway)->toBe('stripe');

    $events = GatewayEvent::query()->where('checkout_id', $checkout->id)->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->gateway)->toBe('stripe');
});

it('fails over from stripe to razorpay on hard failure', function () {
    config(['checkouthub.gateways.stripe.simulate_failure' => true]);

    $checkout = Checkout::factory()->create();
    $result = app(GatewayRouter::class)->charge(chargeRequestFor($checkout));

    expect($result->success)->toBeTrue()
        ->and($result->gateway)->toBe('razorpay');

    $events = GatewayEvent::query()
        ->where('checkout_id', $checkout->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->gateway)->toBe('stripe')
        ->and($events[0]->outcome)->toBe('failover')
        ->and($events[0]->failover_reason)->toBe('hard_failure')
        ->and($events[1]->gateway)->toBe('razorpay')
        ->and($events[1]->outcome)->toBe('success')
        ->and($events[0]->request_hash)->toHaveLength(64)
        ->and($events[0]->response_hash)->toHaveLength(64);
});

it('skips an open circuit and failovers atomically', function () {
    app(CircuitBreaker::class)->forceOpen('stripe');

    $checkout = Checkout::factory()->create();
    $result = app(GatewayRouter::class)->charge(chargeRequestFor($checkout));

    expect($result->success)->toBeTrue()
        ->and($result->gateway)->toBe('razorpay');

    $events = GatewayEvent::query()
        ->where('checkout_id', $checkout->id)
        ->orderBy('id')
        ->get();

    expect($events[0]->gateway)->toBe('stripe')
        ->and($events[0]->outcome)->toBe('skipped')
        ->and($events[0]->failover_reason)->toBe('circuit_open');
});

it('refuses a concurrent duplicate charge on the same checkout', function () {
    $checkout = Checkout::factory()->create();
    $lock = app(\App\Services\ChargeLock::class);
    $held = $lock->acquire($checkout->id);

    expect($held)->not->toBeNull();

    expect(fn () => app(GatewayRouter::class)->charge(chargeRequestFor($checkout)))
        ->toThrow(ConcurrentChargeException::class);

    $lock->release($checkout->id, $held);
});
