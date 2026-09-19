<?php

use App\Enums\CheckoutState;
use App\Exceptions\AmbiguousChargeException;
use App\Exceptions\ConcurrentChargeException;
use App\Gateways\ChargeRequest;
use App\Gateways\GatewayRouter;
use App\Models\Checkout;
use App\Models\GatewayEvent;
use App\Models\OutboundWebhook;
use App\Models\Subscription;
use App\Services\ChargeLock;
use App\Services\CheckoutCompletionService;
use App\Services\CircuitBreaker;
use Illuminate\Support\Facades\Redis;
use function Pest\Laravel\withHeaders;

beforeEach(function () {
    flushTestRedis();
    config([
        'checkouthub.gateways.stripe.mode' => 'simulate',
        'checkouthub.gateways.stripe.simulate_failure' => false,
        'checkouthub.gateways.stripe.simulate_timeout' => false,
        'checkouthub.gateways.stripe.simulate_charge_succeeded' => false,
        'checkouthub.gateways.stripe.simulate_reconcile_timeout' => false,
        'checkouthub.gateways.razorpay.mode' => 'simulate',
        'checkouthub.gateways.razorpay.simulate_failure' => false,
        'checkouthub.gateways.razorpay.simulate_timeout' => false,
        'checkouthub.gateways.razorpay.simulate_charge_succeeded' => false,
        'checkouthub.gateways.razorpay.simulate_reconcile_timeout' => false,
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
        ->and($events[0]->gateway)->toBe('stripe')
        ->and($events[0]->charge_ref)->toBe('ch_'.$checkout->id);
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
        ->and($events[0]->failover_reason)->toBe('circuit_open')
        ->and($events[0]->charge_ref)->toBe('ch_'.$checkout->id)
        ->and($events[1]->charge_ref)->toBe('ch_'.$checkout->id);
});

it('refuses a concurrent duplicate charge on the same checkout', function () {
    $checkout = Checkout::factory()->create();
    $lock = app(ChargeLock::class);
    $held = $lock->acquire($checkout->id);

    expect($held)->not->toBeNull();

    expect(fn () => app(GatewayRouter::class)->charge(chargeRequestFor($checkout)))
        ->toThrow(ConcurrentChargeException::class);

    $lock->release($checkout->id, $held);
});

function payableCheckout(array $overrides = []): Checkout
{
    return Checkout::factory()->create(array_merge([
        'state' => CheckoutState::Pending,
        'total_minor' => 1999,
        'offer_snapshot' => [
            'due_now_minor' => 1999,
            'recurring_minor' => 1999,
            'interval' => 'month',
            'trial_days' => 0,
        ],
    ], $overrides));
}

it('reconciles an ambiguous timeout when the provider actually charged and does not fail over', function () {
    config([
        'checkouthub.gateways.stripe.simulate_timeout' => true,
        'checkouthub.gateways.stripe.simulate_charge_succeeded' => true,
    ]);

    $checkout = payableCheckout();
    $completed = app(CheckoutCompletionService::class)->complete($checkout->store, $checkout);

    expect($completed->state)->toBe(CheckoutState::Complete);

    $events = GatewayEvent::query()
        ->where('checkout_id', $checkout->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->gateway)->toBe('stripe')
        ->and($events[0]->outcome)->toBe('ambiguous')
        ->and($events[0]->failover_reason)->toBe('timeout')
        ->and($events[0]->charge_ref)->toBe('ch_'.$checkout->id)
        ->and($events[1]->gateway)->toBe('stripe')
        ->and($events[1]->outcome)->toBe('success')
        ->and($events[1]->failover_reason)->toBe('reconciled_after_timeout')
        ->and($events->where('gateway', 'razorpay'))->toHaveCount(0)
        ->and($events->where('outcome', 'success'))->toHaveCount(1);

    expect(Subscription::query()->where('checkout_id', $checkout->id)->count())->toBe(1);
});

it('fails over after an ambiguous timeout when the provider has no record', function () {
    config(['checkouthub.gateways.stripe.simulate_timeout' => true]);

    $checkout = payableCheckout();
    $completed = app(CheckoutCompletionService::class)->complete($checkout->store, $checkout);

    expect($completed->state)->toBe(CheckoutState::Complete);

    $events = GatewayEvent::query()
        ->where('checkout_id', $checkout->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->gateway)->toBe('stripe')
        ->and($events[0]->outcome)->toBe('failover')
        ->and($events[0]->failover_reason)->toBe('reconciled_not_found')
        ->and($events[1]->gateway)->toBe('razorpay')
        ->and($events[1]->outcome)->toBe('success')
        ->and($events->where('outcome', 'success'))->toHaveCount(1);

    expect(Subscription::query()->where('checkout_id', $checkout->id)->count())->toBe(1);
});

it('parks the checkout for review when reconciliation also times out and the worker later resolves it', function () {
    config([
        'checkouthub.gateways.stripe.simulate_timeout' => true,
        'checkouthub.gateways.stripe.simulate_reconcile_timeout' => true,
    ]);

    $checkout = payableCheckout();

    expect(fn () => app(GatewayRouter::class)->charge(chargeRequestFor($checkout)))
        ->toThrow(AmbiguousChargeException::class);

    expect($checkout->fresh()->state)->toBe(CheckoutState::PendingReview);

    $events = GatewayEvent::query()
        ->where('checkout_id', $checkout->id)
        ->orderBy('id')
        ->get();

    expect($events->pluck('gateway')->unique()->values()->all())->toBe(['stripe'])
        ->and($events[0]->outcome)->toBe('ambiguous')
        ->and($events[1]->outcome)->toBe('pending_review')
        ->and($events[1]->failover_reason)->toBe('reconcile_inconclusive');

    $job = json_decode((string) Redis::lpop('checkouthub:jobs:charges.reconcile'), true);
    expect($job)->toBeArray()
        ->and($job['checkoutId'])->toBe($checkout->id)
        ->and($job['gateway'])->toBe('stripe')
        ->and($job['chargeRef'])->toBe('ch_'.$checkout->id);

    $parked = app(CheckoutCompletionService::class)->complete($checkout->store, $checkout->fresh());
    expect($parked->state)->toBe(CheckoutState::PendingReview)
        ->and(Subscription::query()->where('checkout_id', $checkout->id)->count())->toBe(0);

    config([
        'checkouthub.gateways.stripe.simulate_timeout' => false,
        'checkouthub.gateways.stripe.simulate_reconcile_timeout' => false,
        'checkouthub.gateways.stripe.simulate_charge_succeeded' => true,
    ]);

    $resolved = withHeaders(['Authorization' => 'Bearer checkouthub-worker-dev-token'])
        ->postJson('/api/v1/internal/checkouts/reconcile', [
            'checkout_id' => $checkout->id,
            'gateway' => 'stripe',
            'charge_ref' => 'ch_'.$checkout->id,
        ])
        ->assertOk()
        ->json();

    expect($resolved['status'])->toBe('completed')
        ->and($checkout->fresh()->state)->toBe(CheckoutState::Complete)
        ->and(Subscription::query()->where('checkout_id', $checkout->id)->count())->toBe(1)
        ->and(GatewayEvent::query()->where('checkout_id', $checkout->id)->where('gateway', 'razorpay')->count())->toBe(0);
});

it('produces exactly one subscription and outbound webhook across overlapping complete calls', function () {
    $checkout = payableCheckout();
    $store = $checkout->store;
    $completion = app(CheckoutCompletionService::class);
    $lock = app(ChargeLock::class);

    $held = $lock->acquire($checkout->id);
    expect($held)->not->toBeNull();
    expect(fn () => $completion->complete($store, $checkout->fresh()))
        ->toThrow(ConcurrentChargeException::class);
    $lock->release($checkout->id, $held);

    $first = $completion->complete($store, $checkout->fresh());
    $second = $completion->complete($store, $checkout->fresh());

    expect($first->state)->toBe(CheckoutState::Complete)
        ->and($second->state)->toBe(CheckoutState::Complete)
        ->and(Subscription::query()->where('checkout_id', $checkout->id)->count())->toBe(1)
        ->and(OutboundWebhook::query()->where('idempotency_key', 'checkout-completed-'.$checkout->id)->count())->toBe(1)
        ->and(GatewayEvent::query()->where('checkout_id', $checkout->id)->where('outcome', 'success')->count())->toBe(1);

    $replay = payableCheckout();
    GatewayEvent::query()->create([
        'checkout_id' => $replay->id,
        'gateway' => 'stripe',
        'attempt_no' => 1,
        'request_hash' => str_repeat('a', 64),
        'response_hash' => str_repeat('b', 64),
        'latency_ms' => 12,
        'outcome' => 'success',
        'charge_id' => 'pi_prior_success',
        'charge_ref' => 'ch_'.$replay->id,
    ]);

    $fromPrior = $completion->complete($replay->store, $replay->fresh());
    $fromPriorAgain = $completion->complete($replay->store, $replay->fresh());

    expect($fromPrior->state)->toBe(CheckoutState::Complete)
        ->and($fromPriorAgain->state)->toBe(CheckoutState::Complete)
        ->and(Subscription::query()->where('checkout_id', $replay->id)->count())->toBe(1)
        ->and(OutboundWebhook::query()->where('idempotency_key', 'checkout-completed-'.$replay->id)->count())->toBe(1)
        ->and(GatewayEvent::query()->where('checkout_id', $replay->id)->where('outcome', 'success')->count())->toBe(1)
        ->and(GatewayEvent::query()->where('checkout_id', $replay->id)->where('gateway', 'razorpay')->count())->toBe(0);
});
