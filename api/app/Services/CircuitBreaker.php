<?php

namespace App\Services;

use App\Enums\CircuitState;
use App\Support\Lua;
use Illuminate\Support\Facades\Redis;

final class CircuitBreaker
{
    public function allowRequest(string $gateway): bool
    {
        $state = $this->state($gateway);

        if ($state === CircuitState::Closed) {
            return true;
        }

        $decision = (int) Lua::eval('circuit_claim_probe', [
            $this->key($gateway, 'state'),
            $this->key($gateway, 'opened_at'),
            $this->key($gateway, 'probe'),
        ], [
            (string) $this->nowMs(),
            (string) config('checkouthub.circuit_breaker.cooldown_ms'),
            (string) config('checkouthub.circuit_breaker.probe_ttl_ms'),
        ]);

        return $decision > 0;
    }

    public function recordSuccess(string $gateway, int $latencyMs): void
    {
        Redis::set($this->key($gateway, 'state'), CircuitState::Closed->value);
        Redis::del($this->key($gateway, 'failures'));
        Redis::del($this->key($gateway, 'opened_at'));
        Redis::del($this->key($gateway, 'probe'));

        $this->updateLatencyAndHealth($gateway, $latencyMs, CircuitState::Closed, 0);
    }

    public function recordHardFailure(string $gateway, int $latencyMs): void
    {
        $wasHalfOpen = $this->state($gateway) === CircuitState::HalfOpen;

        $failures = (int) Lua::eval('circuit_record_failure', [
            $this->key($gateway, 'failures'),
            $this->key($gateway, 'state'),
            $this->key($gateway, 'opened_at'),
            $this->key($gateway, 'probe'),
        ], [
            (string) config('checkouthub.circuit_breaker.failure_threshold'),
            (string) config('checkouthub.circuit_breaker.window_ms'),
            (string) $this->nowMs(),
            $wasHalfOpen ? '1' : '0',
        ]);

        $this->updateLatencyAndHealth($gateway, $latencyMs, $this->state($gateway), $failures);
    }

    public function snapshot(string $gateway): array
    {
        $state = $this->state($gateway);
        $failures = (int) (Redis::get($this->key($gateway, 'failures')) ?: 0);
        $ema = (float) (Redis::get($this->key($gateway, 'latency_ema')) ?: 0);
        $health = (float) (Redis::get($this->key($gateway, 'health')) ?: $this->deriveHealth($state, $ema, $failures));

        return [
            'name' => $gateway,
            'state' => $state->value,
            'health' => round($health, 1),
            'latency_ema_ms' => round($ema, 1),
            'failures' => $failures,
            'opened_at' => Redis::get($this->key($gateway, 'opened_at')),
        ];
    }

    public function forceOpen(string $gateway): void
    {
        Redis::set($this->key($gateway, 'state'), CircuitState::Open->value);
        Redis::set($this->key($gateway, 'opened_at'), (string) $this->nowMs());
        Redis::set($this->key($gateway, 'health'), '0');
        Redis::del($this->key($gateway, 'probe'));
    }

    public function forceClosed(string $gateway): void
    {
        Redis::set($this->key($gateway, 'state'), CircuitState::Closed->value);
        Redis::del($this->key($gateway, 'failures'));
        Redis::del($this->key($gateway, 'opened_at'));
        Redis::del($this->key($gateway, 'probe'));
        Redis::set($this->key($gateway, 'health'), '100');
    }

    public function state(string $gateway): CircuitState
    {
        $raw = Redis::get($this->key($gateway, 'state'));

        return CircuitState::tryFrom((string) $raw) ?? CircuitState::Closed;
    }

    public function reset(string $gateway): void
    {
        Redis::del($this->key($gateway, 'state'));
        Redis::del($this->key($gateway, 'failures'));
        Redis::del($this->key($gateway, 'opened_at'));
        Redis::del($this->key($gateway, 'probe'));
        Redis::del($this->key($gateway, 'latency_ema'));
        Redis::del($this->key($gateway, 'health'));
    }

    private function updateLatencyAndHealth(string $gateway, int $latencyMs, CircuitState $state, int $failures): void
    {
        $alpha = (float) config('checkouthub.circuit_breaker.ema_alpha', 0.3);
        $previous = (float) (Redis::get($this->key($gateway, 'latency_ema')) ?: $latencyMs);
        $ema = ($alpha * $latencyMs) + ((1 - $alpha) * $previous);
        $health = $this->deriveHealth($state, $ema, $failures);

        Redis::set($this->key($gateway, 'latency_ema'), (string) $ema);
        Redis::set($this->key($gateway, 'health'), (string) $health);
    }

    private function deriveHealth(CircuitState $state, float $ema, int $failures): float
    {
        if ($state === CircuitState::Open) {
            return 0.0;
        }

        $base = $state === CircuitState::HalfOpen ? 50.0 : 100.0;

        return max(0, min(100, $base - ($ema / 20) - ($failures * 10)));
    }

    private function key(string $gateway, string $suffix): string
    {
        return "cb:{$gateway}:{$suffix}";
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
