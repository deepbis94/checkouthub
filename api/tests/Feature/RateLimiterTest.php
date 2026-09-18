<?php

use App\Services\TokenBucket;

beforeEach(function () {
    flushTestRedis();
});

it('enforces a token bucket atomically', function () {
    $bucket = app(TokenBucket::class);
    $allowed = 0;

    for ($i = 0; $i < 45; $i++) {
        if ($bucket->allow('shopify:test-store', refillPerSecond: 2, capacity: 40)) {
            $allowed++;
        }
    }

    expect($allowed)->toBe(40)
        ->and($bucket->allow('shopify:test-store', refillPerSecond: 2, capacity: 40))->toBeFalse();
});

it('refills tokens over time', function () {
    $bucket = app(TokenBucket::class);
    $key = 'gw:stripe-refill';

    expect($bucket->allow($key, refillPerSecond: 10, capacity: 1))->toBeTrue();
    expect($bucket->allow($key, refillPerSecond: 10, capacity: 1))->toBeFalse();

    usleep(150_000);

    expect($bucket->allow($key, refillPerSecond: 10, capacity: 1))->toBeTrue();
});
