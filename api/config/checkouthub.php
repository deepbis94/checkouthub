<?php

return [

    'worker_token' => env('CHECKOUTHUB_WORKER_TOKEN', 'checkouthub-worker-dev-token'),

    'charge_lock_ttl_ms' => (int) env('CHECKOUTHUB_CHARGE_LOCK_TTL_MS', 15000),

    'checkout_ttl_seconds' => (int) env('CHECKOUTHUB_CHECKOUT_TTL', 1800),

    'webhook_max_attempts' => (int) env('CHECKOUTHUB_WEBHOOK_ATTEMPTS', 8),

    'circuit_breaker' => [
        'failure_threshold' => (int) env('CHECKOUTHUB_CB_THRESHOLD', 5),
        'window_ms' => (int) env('CHECKOUTHUB_CB_WINDOW_MS', 60000),
        'cooldown_ms' => (int) env('CHECKOUTHUB_CB_COOLDOWN_MS', 15000),
        'probe_ttl_ms' => (int) env('CHECKOUTHUB_CB_PROBE_TTL_MS', 5000),
        'ema_alpha' => (float) env('CHECKOUTHUB_CB_EMA_ALPHA', 0.3),
    ],

    'rate_limits' => [
        'store' => [
            'capacity' => (int) env('CHECKOUTHUB_STORE_BUCKET_CAPACITY', 60),
            'refill_per_second' => (float) env('CHECKOUTHUB_STORE_BUCKET_REFILL', 10),
        ],
        'shopify_rest' => [
            'capacity' => 40,
            'refill_per_second' => 2,
        ],
        'bigcommerce' => [
            'capacity' => 20,
            'refill_per_second' => 5,
        ],
        'stripe' => [
            'capacity' => 25,
            'refill_per_second' => 10,
        ],
        'razorpay' => [
            'capacity' => 25,
            'refill_per_second' => 10,
        ],
    ],

    'gateways' => [
        'stripe' => [
            'adapter' => App\Gateways\StripeAdapter::class,
            'priority' => (int) env('STRIPE_PRIORITY', 1),
            'enabled' => (bool) env('STRIPE_ENABLED', true),
            'mode' => env('STRIPE_MODE', 'simulate'),
            'secret' => env('STRIPE_SECRET'),
            'simulate_failure' => (bool) env('STRIPE_SIMULATE_FAILURE', false),
            'simulate_latency_ms' => (int) env('STRIPE_SIMULATE_LATENCY_MS', 25),
            'simulate_timeout' => (bool) env('STRIPE_SIMULATE_TIMEOUT', false),
            'simulate_charge_succeeded' => (bool) env('STRIPE_SIMULATE_CHARGE_SUCCEEDED', false),
            'simulate_reconcile_timeout' => (bool) env('STRIPE_SIMULATE_RECONCILE_TIMEOUT', false),
        ],
        'razorpay' => [
            'adapter' => App\Gateways\RazorpayAdapter::class,
            'priority' => (int) env('RAZORPAY_PRIORITY', 2),
            'enabled' => (bool) env('RAZORPAY_ENABLED', true),
            'mode' => env('RAZORPAY_MODE', 'simulate'),
            'key' => env('RAZORPAY_KEY'),
            'secret' => env('RAZORPAY_SECRET'),
            'simulate_failure' => (bool) env('RAZORPAY_SIMULATE_FAILURE', false),
            'simulate_latency_ms' => (int) env('RAZORPAY_SIMULATE_LATENCY_MS', 30),
            'simulate_timeout' => (bool) env('RAZORPAY_SIMULATE_TIMEOUT', false),
            'simulate_charge_succeeded' => (bool) env('RAZORPAY_SIMULATE_CHARGE_SUCCEEDED', false),
            'simulate_reconcile_timeout' => (bool) env('RAZORPAY_SIMULATE_RECONCILE_TIMEOUT', false),
        ],
    ],

];
