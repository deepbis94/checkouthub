<?php

use App\Services\CircuitBreaker;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    flushTestRedis();
    config([
        'checkouthub.circuit_breaker.failure_threshold' => 3,
        'checkouthub.circuit_breaker.window_ms' => 60000,
        'checkouthub.circuit_breaker.cooldown_ms' => 20,
        'checkouthub.circuit_breaker.probe_ttl_ms' => 5000,
    ]);
});

it('opens after consecutive hard failures via atomic INCR', function () {
    $breaker = app(CircuitBreaker::class);

    $breaker->recordHardFailure('stripe', 40);
    $breaker->recordHardFailure('stripe', 50);
    expect($breaker->state('stripe')->value)->toBe('closed');
    expect($breaker->allowRequest('stripe'))->toBeTrue();

    $breaker->recordHardFailure('stripe', 80);
    expect($breaker->state('stripe')->value)->toBe('open');
    expect($breaker->allowRequest('stripe'))->toBeFalse();
    expect($breaker->snapshot('stripe')['health'])->toBe(0.0);
});

it('allows a single half-open probe after cooldown', function () {
    $breaker = app(CircuitBreaker::class);
    $breaker->forceOpen('stripe');

    Redis::set('cb:stripe:opened_at', (string) ((int) floor(microtime(true) * 1000) - 1000));

    expect($breaker->allowRequest('stripe'))->toBeTrue()
        ->and($breaker->state('stripe')->value)->toBe('half_open')
        ->and($breaker->allowRequest('stripe'))->toBeFalse();

    $breaker->recordSuccess('stripe', 12);

    expect($breaker->state('stripe')->value)->toBe('closed')
        ->and($breaker->allowRequest('stripe'))->toBeTrue();
});

it('re-opens from half-open on a failed probe', function () {
    $breaker = app(CircuitBreaker::class);
    $breaker->forceOpen('stripe');
    Redis::set('cb:stripe:opened_at', (string) ((int) floor(microtime(true) * 1000) - 1000));

    expect($breaker->allowRequest('stripe'))->toBeTrue();

    $breaker->recordHardFailure('stripe', 200);

    expect($breaker->state('stripe')->value)->toBe('open')
        ->and($breaker->allowRequest('stripe'))->toBeFalse();
});
