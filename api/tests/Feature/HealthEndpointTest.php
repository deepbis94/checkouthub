<?php

use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    flushTestRedis();
});

it('reports gateway circuit breaker states', function () {
    $this->seed(DatabaseSeeder::class);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure([
            'status',
            'correlation_id',
            'gateways' => [
                '*' => ['name', 'state', 'health', 'latency_ema_ms', 'failures', 'priority'],
            ],
        ]);
});

it('requires a store api key for gateway events', function () {
    $this->getJson('/api/v1/gateway-events')->assertUnauthorized();

    $this->seed(DatabaseSeeder::class);

    $this->withHeaders(['X-Api-Key' => DatabaseSeeder::DEMO_API_KEY])
        ->getJson('/api/v1/gateway-events')
        ->assertOk()
        ->assertJsonStructure(['data']);
});
