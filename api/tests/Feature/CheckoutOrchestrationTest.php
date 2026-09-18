<?php

use App\Models\Checkout;
use App\Models\Offer;
use App\Models\Store;
use App\Models\Subscription;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    flushTestRedis();
    config([
        'checkouthub.gateways.stripe.mode' => 'simulate',
        'checkouthub.gateways.stripe.simulate_failure' => false,
        'checkouthub.gateways.razorpay.mode' => 'simulate',
        'checkouthub.gateways.razorpay.simulate_failure' => false,
        'checkouthub.rate_limits.store.capacity' => 1000,
        'checkouthub.rate_limits.store.refill_per_second' => 1000,
    ]);
});

function storeHeaders(?string $idempotency = null): array
{
    $headers = ['X-Api-Key' => DatabaseSeeder::DEMO_API_KEY];

    if ($idempotency) {
        $headers['Idempotency-Key'] = $idempotency;
    }

    return $headers;
}

it('creates a checkout with an immutable offer snapshot and ttl', function () {
    $this->seed(DatabaseSeeder::class);
    $offer = Offer::query()->where('slug', 'pro-monthly')->first();

    $response = $this->withHeaders(storeHeaders())
        ->postJson('/api/v1/checkouts', [
            'offer_id' => $offer->id,
            'customer_email' => 'buyer@example.com',
            'expires_in' => 600,
        ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending');

    $id = $response->json('data.id');
    expect($id)->toBeString()
        ->and($response->json('data.offer_snapshot.due_now_minor'))->toBe(0)
        ->and($response->json('data.offer_snapshot.recurring_minor'))->toBe(2498)
        ->and($response->json('data.expires_in'))->toBeGreaterThan(500);

    $this->assertDatabaseHas('checkout_transitions', [
        'checkout_id' => $id,
        'to_state' => 'pending',
        'actor' => 'api',
    ]);
});

it('returns the same checkout for a duplicate idempotency key', function () {
    $this->seed(DatabaseSeeder::class);
    $offer = Offer::query()->first();
    $headers = storeHeaders('create-abc-123');
    $payload = ['offer_id' => $offer->id, 'customer_email' => 'buyer@example.com'];

    $first = $this->withHeaders($headers)->postJson('/api/v1/checkouts', $payload)->assertCreated();
    $second = $this->withHeaders($headers)->postJson('/api/v1/checkouts', $payload)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Checkout::query()->count())->toBe(1);
});

it('applies discount codes and stacks eligible upsells into frozen pricing', function () {
    $this->seed(DatabaseSeeder::class);
    $offer = Offer::query()->first();

    $this->withHeaders(storeHeaders())
        ->postJson('/api/v1/checkouts', [
            'offer_id' => $offer->id,
            'discount_code' => 'SAVE20',
            'customer_email' => 'buyer@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('data.discount_minor', 499)
        ->assertJsonPath('data.offer_snapshot.recurring_minor', 1999)
        ->assertJsonPath('data.offer_snapshot.due_now_minor', 0);
});

it('completes a checkout through authorizing and creates a subscription', function () {
    $this->seed(DatabaseSeeder::class);
    $offer = Offer::query()->first();
    $offer->update(['trial_days' => 0]);

    $id = $this->withHeaders(storeHeaders())
        ->postJson('/api/v1/checkouts', [
            'offer_id' => $offer->id,
            'customer_email' => 'buyer@example.com',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withHeaders(storeHeaders())
        ->postJson("/api/v1/checkouts/{$id}/complete")
        ->assertOk()
        ->assertJsonPath('data.state', 'complete');

    expect(Subscription::query()->where('checkout_id', $id)->exists())->toBeTrue();

    $this->withHeaders(storeHeaders())
        ->getJson('/api/v1/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.0.checkout_id', $id);

    $this->assertDatabaseHas('checkout_transitions', ['checkout_id' => $id, 'from_state' => 'pending', 'to_state' => 'authorizing']);
    $this->assertDatabaseHas('checkout_transitions', ['checkout_id' => $id, 'from_state' => 'authorizing', 'to_state' => 'complete']);
});

it('is idempotent when complete is retried on an already completed checkout', function () {
    $this->seed(DatabaseSeeder::class);
    $offer = Offer::query()->first();
    $offer->update(['trial_days' => 0]);

    $id = $this->withHeaders(storeHeaders())
        ->postJson('/api/v1/checkouts', ['offer_id' => $offer->id, 'customer_email' => 'a@b.com'])
        ->json('data.id');

    $this->withHeaders(storeHeaders())->postJson("/api/v1/checkouts/{$id}/complete")->assertOk();
    $this->withHeaders(storeHeaders())->postJson("/api/v1/checkouts/{$id}/complete")
        ->assertOk()
        ->assertJsonPath('data.state', 'complete');

    expect(Subscription::query()->where('checkout_id', $id)->count())->toBe(1);
});

it('fails over during complete when stripe is down', function () {
    $this->seed(DatabaseSeeder::class);
    config(['checkouthub.gateways.stripe.simulate_failure' => true]);
    $offer = Offer::query()->first();
    $offer->update(['trial_days' => 0]);

    $id = $this->withHeaders(storeHeaders())
        ->postJson('/api/v1/checkouts', ['offer_id' => $offer->id, 'customer_email' => 'a@b.com'])
        ->json('data.id');

    $this->withHeaders(storeHeaders())
        ->postJson("/api/v1/checkouts/{$id}/complete")
        ->assertOk()
        ->assertJsonPath('data.state', 'complete');

    $this->assertDatabaseHas('gateway_events', ['checkout_id' => $id, 'gateway' => 'stripe', 'outcome' => 'failover']);
    $this->assertDatabaseHas('gateway_events', ['checkout_id' => $id, 'gateway' => 'razorpay', 'outcome' => 'success']);
});

it('rejects completing an expired checkout', function () {
    $this->seed(DatabaseSeeder::class);
    $store = Store::query()->first();
    $checkout = Checkout::factory()->create([
        'store_id' => $store->id,
        'offer_id' => Offer::query()->first()->id,
        'expires_at' => now()->subMinute(),
        'state' => 'pending',
    ]);

    $this->withHeaders(storeHeaders())
        ->postJson("/api/v1/checkouts/{$checkout->id}/complete")
        ->assertStatus(410);
});

it('sweeps stale checkouts to expired', function () {
    $this->seed(DatabaseSeeder::class);
    $store = Store::query()->first();
    $checkout = Checkout::factory()->create([
        'store_id' => $store->id,
        'offer_id' => Offer::query()->first()->id,
        'expires_at' => now()->subMinute(),
        'state' => 'pending',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer checkouthub-worker-dev-token'])
        ->postJson('/api/v1/internal/checkouts/expire')
        ->assertOk()
        ->assertJsonFragment(['expired' => [$checkout->id]]);

    expect($checkout->fresh()->state->value)->toBe('expired');
});

it('lists offers for the authenticated store', function () {
    $this->seed(DatabaseSeeder::class);

    $this->withHeaders(storeHeaders())
        ->getJson('/api/v1/offers')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'pro-monthly');
});
