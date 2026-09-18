<?php

use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    flushTestRedis();
    $this->seed(DatabaseSeeder::class);
});

function postSignedShopify(array $payload, array $extra = []): TestResponse
{
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $secret = $extra['secret'] ?? DatabaseSeeder::SHOPIFY_WEBHOOK_SECRET;
    $hmac = base64_encode(hash_hmac('sha256', $raw, $secret, true));

    return test()->call(
        'POST',
        '/api/v1/webhooks/shopify',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $extra['hmac'] ?? $hmac,
            'HTTP_X_SHOPIFY_TOPIC' => $extra['topic'] ?? 'customers/create',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $extra['shop'] ?? 'demo-shop.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $extra['id'] ?? 'shopify-evt-1',
        ],
        $raw,
    );
}

function postSignedBigCommerce(array $payload, array $extra = []): TestResponse
{
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $secret = $extra['secret'] ?? DatabaseSeeder::BIGCOMMERCE_WEBHOOK_SECRET;
    $signature = base64_encode(hash_hmac('sha256', $raw, $secret, true));

    return test()->call(
        'POST',
        '/api/v1/webhooks/bigcommerce',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BC_WEBHOOK_SIGNATURE' => $extra['signature'] ?? $signature,
            'HTTP_X_BC_WEBHOOK_ID' => $extra['id'] ?? null,
        ],
        $raw,
    );
}

it('accepts a valid shopify hmac and upserts a customer', function () {
    postSignedShopify([
        'id' => 9001,
        'email' => 'buyer@example.com',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ])->assertOk()->assertJsonPath('duplicate', false);

    expect(Customer::query()->where('email', 'buyer@example.com')->count())->toBe(1)
        ->and(WebhookEvent::query()->where('provider', 'shopify')->where('event_id', 'shopify-evt-1')->exists())->toBeTrue();
});

it('rejects an invalid shopify hmac', function () {
    postSignedShopify(['id' => 1, 'email' => 'x@y.com'], ['hmac' => 'not-a-valid-hmac'])
        ->assertUnauthorized();

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});

it('returns 200 immediately for duplicate shopify event ids', function () {
    $payload = ['id' => 44, 'email' => 'dup@example.com', 'first_name' => 'Dup'];

    postSignedShopify($payload, ['id' => 'same-event'])->assertOk()->assertJsonPath('duplicate', false);
    postSignedShopify($payload, ['id' => 'same-event'])->assertOk()->assertJsonPath('duplicate', true);

    expect(Customer::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->count())->toBe(1);
});

it('links a shopify order to a checkout via note attributes', function () {
    $store = Store::query()->where('platform', 'shopify')->first();
    $checkout = Checkout::factory()->create(['store_id' => $store->id]);

    postSignedShopify([
        'id' => 555,
        'email' => 'buyer@example.com',
        'financial_status' => 'paid',
        'total_price' => '19.99',
        'currency' => 'USD',
        'note_attributes' => [
            ['name' => 'checkouthub_checkout_id', 'value' => $checkout->id],
        ],
    ], [
        'topic' => 'orders/create',
        'id' => 'order-evt-1',
    ])->assertOk();

    $order = StoreOrder::query()->where('provider_order_id', '555')->first();
    expect($order)->not->toBeNull()
        ->and($order->checkout_id)->toBe($checkout->id)
        ->and($order->total_minor)->toBe(1999)
        ->and($order->status)->toBe('paid');
});

it('cancels a subscription from a shopify subscription webhook', function () {
    $store = Store::query()->where('platform', 'shopify')->first();
    $subscription = Subscription::query()->create([
        'store_id' => $store->id,
        'customer_email' => 'sub@example.com',
        'status' => 'active',
        'amount_minor' => 1999,
        'currency' => 'USD',
        'gateway_subscription_id' => '999',
        'next_renewal_at' => now()->addMonth(),
    ]);

    postSignedShopify([
        'id' => 999,
        'email' => 'sub@example.com',
        'status' => 'cancelled',
    ], [
        'topic' => 'subscription_contracts/cancel',
        'id' => 'sub-cancel-1',
    ])->assertOk();

    expect($subscription->fresh()->status)->toBe('cancelled')
        ->and($subscription->fresh()->next_renewal_at)->toBeNull();
});

it('accepts a bigcommerce hmac and upserts a customer', function () {
    postSignedBigCommerce([
        'scope' => 'store/customer/created',
        'producer' => 'stores/abcde',
        'hash' => 'bc-hash-1',
        'data' => [
            'id' => 77,
            'email' => 'bc@example.com',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
        ],
    ])->assertOk()->assertJsonPath('duplicate', false);

    expect(Customer::query()->where('email', 'bc@example.com')->exists())->toBeTrue();
});

it('accepts a bigcommerce signed payload without the signature header', function () {
    $inner = base64_encode('{"store_hash":"abcde"}');
    $signature = hash_hmac('sha256', $inner, DatabaseSeeder::BIGCOMMERCE_WEBHOOK_SECRET);
    $payload = [
        'signed_payload' => $inner.'.'.$signature,
        'scope' => 'store/customer/created',
        'producer' => 'stores/abcde',
        'hash' => 'bc-signed-1',
        'data' => ['id' => 12, 'email' => 'signed@example.com'],
    ];
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

    test()->call(
        'POST',
        '/api/v1/webhooks/bigcommerce',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $raw,
    )->assertOk();

    expect(Customer::query()->where('email', 'signed@example.com')->exists())->toBeTrue();
});

it('rejects webhooks for an unknown shop', function () {
    postSignedShopify(['id' => 1], ['shop' => 'missing.myshopify.com'])->assertUnauthorized();
});
