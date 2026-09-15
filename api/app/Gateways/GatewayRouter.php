<?php

namespace App\Gateways;

use App\Enums\GatewayOutcome;
use App\Exceptions\ConcurrentChargeException;
use App\Exceptions\HardGatewayException;
use App\Models\GatewayEvent;
use App\Services\ChargeLock;
use App\Services\CircuitBreaker;
use App\Services\TokenBucket;
use App\Support\Correlation;
use App\Support\PayloadHasher;
use Illuminate\Support\Facades\Log;

final class GatewayRouter
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly CircuitBreaker $breaker,
        private readonly ChargeLock $chargeLock,
        private readonly TokenBucket $tokenBucket,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        $token = $this->chargeLock->acquire($request->checkoutId);

        if ($token === null) {
            throw new ConcurrentChargeException($request->checkoutId);
        }

        try {
            return $this->attemptCharge($request);
        } finally {
            $this->chargeLock->release($request->checkoutId, $token);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function health(): array
    {
        return collect($this->registry->enabledByPriority())
            ->map(function ($adapter) {
                return array_merge($this->breaker->snapshot($adapter->name()), [
                    'priority' => $adapter->priority(),
                ]);
            })
            ->values()
            ->all();
    }

    public function probeAll(): array
    {
        $results = [];

        foreach ($this->registry->enabledByPriority() as $adapter) {
            $probe = $adapter->healthProbe();

            if ($probe->ok) {
                $this->breaker->recordSuccess($adapter->name(), $probe->latencyMs);
            } else {
                $this->breaker->recordHardFailure($adapter->name(), $probe->latencyMs);
            }

            $results[] = [
                'gateway' => $probe->gateway,
                'ok' => $probe->ok,
                'latency_ms' => $probe->latencyMs,
                'message' => $probe->message,
                'breaker' => $this->breaker->snapshot($adapter->name()),
            ];
        }

        return $results;
    }

    private function attemptCharge(ChargeRequest $request): ChargeResult
    {
        $adapters = $this->registry->enabledByPriority();
        $attemptNo = 0;
        $lastFailure = null;

        foreach ($adapters as $index => $adapter) {
            $attemptNo++;
            $name = $adapter->name();
            $hasNext = isset($adapters[$index + 1]);

            if (! $this->breaker->allowRequest($name)) {
                $this->record($request, $name, $attemptNo, new ChargeResult(
                    success: false,
                    gateway: $name,
                    chargeId: null,
                    latencyMs: 0,
                    requestHash: PayloadHasher::hash($request->toHashableArray()),
                    responseHash: PayloadHasher::hash(['skipped' => 'circuit_open']),
                    outcome: GatewayOutcome::Skipped->value,
                    failoverReason: 'circuit_open',
                ));

                Log::warning('gateway.skipped_open_circuit', [
                    'gateway' => $name,
                    'checkout_id' => $request->checkoutId,
                    'correlation_id' => Correlation::id(),
                ]);

                continue;
            }

            $limit = config("checkouthub.rate_limits.{$name}", ['capacity' => 25, 'refill_per_second' => 10]);

            if (! $this->tokenBucket->allow("gateway:{$name}", (float) $limit['refill_per_second'], (int) $limit['capacity'])) {
                $this->record($request, $name, $attemptNo, new ChargeResult(
                    success: false,
                    gateway: $name,
                    chargeId: null,
                    latencyMs: 0,
                    requestHash: PayloadHasher::hash($request->toHashableArray()),
                    responseHash: PayloadHasher::hash(['skipped' => 'rate_limited']),
                    outcome: GatewayOutcome::Skipped->value,
                    failoverReason: 'rate_limited',
                ));

                continue;
            }

            try {
                $result = $adapter->charge($request);

                if ($result->success) {
                    $this->breaker->recordSuccess($name, $result->latencyMs);
                    $this->record($request, $name, $attemptNo, $result);

                    Log::info('gateway.charge_succeeded', [
                        'gateway' => $name,
                        'checkout_id' => $request->checkoutId,
                        'latency_ms' => $result->latencyMs,
                        'correlation_id' => Correlation::id(),
                    ]);

                    return $result;
                }

                $this->record($request, $name, $attemptNo, $result);

                return $result;
            } catch (HardGatewayException $e) {
                $this->breaker->recordHardFailure($name, $e->latencyMs);

                $outcome = $hasNext ? GatewayOutcome::Failover : GatewayOutcome::HardFailure;
                $failed = new ChargeResult(
                    success: false,
                    gateway: $name,
                    chargeId: null,
                    latencyMs: $e->latencyMs,
                    requestHash: $e->requestHash,
                    responseHash: $e->responseHash,
                    outcome: $outcome->value,
                    failoverReason: $hasNext ? $e->reason : null,
                    errorCode: $e->errorCode,
                    message: $e->getMessage(),
                );

                $this->record($request, $name, $attemptNo, $failed);
                $lastFailure = $failed;

                Log::warning('gateway.hard_failure', [
                    'gateway' => $name,
                    'checkout_id' => $request->checkoutId,
                    'reason' => $e->reason,
                    'will_failover' => $hasNext,
                    'correlation_id' => Correlation::id(),
                ]);
            }
        }

        return $lastFailure ?? new ChargeResult(
            success: false,
            gateway: 'none',
            chargeId: null,
            latencyMs: 0,
            requestHash: PayloadHasher::hash($request->toHashableArray()),
            responseHash: PayloadHasher::hash(['error' => 'no_available_gateway']),
            outcome: GatewayOutcome::HardFailure->value,
            failoverReason: 'exhausted',
            message: 'No available payment gateway',
        );
    }

    private function record(ChargeRequest $request, string $gateway, int $attemptNo, ChargeResult $result): void
    {
        GatewayEvent::query()->create([
            'checkout_id' => $request->checkoutId,
            'gateway' => $gateway,
            'attempt_no' => $attemptNo,
            'request_hash' => $result->requestHash,
            'response_hash' => $result->responseHash,
            'latency_ms' => $result->latencyMs,
            'outcome' => $result->outcome,
            'failover_reason' => $result->failoverReason,
            'charge_id' => $result->chargeId,
            'meta' => [
                'error_code' => $result->errorCode,
                'message' => $result->message,
                'correlation_id' => Correlation::id(),
            ],
        ]);
    }
}
