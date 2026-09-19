<?php

namespace App\Gateways;

use App\Contracts\GatewayAdapter;
use App\Domain\Checkout\CheckoutStateMachine;
use App\Enums\CheckoutState;
use App\Enums\GatewayOutcome;
use App\Exceptions\AmbiguousChargeException;
use App\Exceptions\AmbiguousGatewayException;
use App\Exceptions\ConcurrentChargeException;
use App\Exceptions\ConcurrentCheckoutTransitionException;
use App\Exceptions\HardGatewayException;
use App\Models\Checkout;
use App\Models\GatewayEvent;
use App\Services\ChargeLock;
use App\Services\CircuitBreaker;
use App\Services\TokenBucket;
use App\Support\Correlation;
use App\Support\PayloadHasher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class GatewayRouter
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly CircuitBreaker $breaker,
        private readonly ChargeLock $chargeLock,
        private readonly TokenBucket $tokenBucket,
        private readonly CheckoutStateMachine $states,
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
        $chargeRef = $request->chargeRef();

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
                    'charge_ref' => $chargeRef,
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
                        'charge_ref' => $chargeRef,
                        'latency_ms' => $result->latencyMs,
                        'correlation_id' => Correlation::id(),
                    ]);

                    return $result;
                }

                $this->record($request, $name, $attemptNo, $result);

                return $result;
            } catch (AmbiguousGatewayException $e) {
                $this->breaker->recordHardFailure($name, $e->latencyMs);

                $resolved = $this->reconcileAmbiguous($adapter, $request, $attemptNo, $e, $hasNext);

                if ($resolved->success) {
                    return $resolved;
                }

                $lastFailure = $resolved;

                Log::warning('gateway.hard_failure', [
                    'gateway' => $name,
                    'checkout_id' => $request->checkoutId,
                    'charge_ref' => $chargeRef,
                    'reason' => 'reconciled_not_found',
                    'will_failover' => $hasNext,
                    'correlation_id' => Correlation::id(),
                ]);
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
                    'charge_ref' => $chargeRef,
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

    private function reconcileAmbiguous(
        GatewayAdapter $adapter,
        ChargeRequest $request,
        int $attemptNo,
        AmbiguousGatewayException $e,
        bool $hasNext,
    ): ChargeResult {
        $name = $adapter->name();
        $ambiguous = new ChargeResult(
            success: false,
            gateway: $name,
            chargeId: null,
            latencyMs: $e->latencyMs,
            requestHash: $e->requestHash,
            responseHash: $e->responseHash,
            outcome: GatewayOutcome::Ambiguous->value,
            failoverReason: $e->reason,
            errorCode: $e->errorCode,
            message: $e->getMessage(),
        );

        try {
            $found = $adapter->fetchCharge($request->chargeRef());
        } catch (AmbiguousGatewayException $reconcileError) {
            $this->record($request, $name, $attemptNo, $ambiguous);
            $this->record($request, $name, $attemptNo, new ChargeResult(
                success: false,
                gateway: $name,
                chargeId: null,
                latencyMs: $reconcileError->latencyMs,
                requestHash: $reconcileError->requestHash,
                responseHash: $reconcileError->responseHash,
                outcome: GatewayOutcome::PendingReview->value,
                failoverReason: 'reconcile_inconclusive',
                errorCode: $reconcileError->errorCode,
                message: $reconcileError->getMessage(),
            ));

            Log::critical('gateway.reconcile_inconclusive', [
                'gateway' => $name,
                'checkout_id' => $request->checkoutId,
                'charge_ref' => $request->chargeRef(),
                'correlation_id' => Correlation::id(),
            ]);

            $this->parkForReview($request, $name);

            throw new AmbiguousChargeException($request->checkoutId, $name, $request->chargeRef());
        }

        if ($found?->success) {
            $this->record($request, $name, $attemptNo, $ambiguous);

            $success = new ChargeResult(
                success: true,
                gateway: $name,
                chargeId: $found->chargeId,
                latencyMs: $found->latencyMs,
                requestHash: $found->requestHash !== '' ? $found->requestHash : $e->requestHash,
                responseHash: $found->responseHash,
                outcome: GatewayOutcome::Success->value,
                failoverReason: 'reconciled_after_timeout',
            );

            $this->breaker->recordSuccess($name, $success->latencyMs);
            $this->record($request, $name, $attemptNo, $success);

            Log::info('gateway.reconciled_after_timeout', [
                'gateway' => $name,
                'checkout_id' => $request->checkoutId,
                'charge_ref' => $request->chargeRef(),
                'charge_id' => $success->chargeId,
                'correlation_id' => Correlation::id(),
            ]);

            return $success;
        }

        $failed = new ChargeResult(
            success: false,
            gateway: $name,
            chargeId: null,
            latencyMs: $e->latencyMs,
            requestHash: $e->requestHash,
            responseHash: $e->responseHash,
            outcome: $hasNext ? GatewayOutcome::Failover->value : GatewayOutcome::HardFailure->value,
            failoverReason: 'reconciled_not_found',
            errorCode: $e->errorCode,
            message: $e->getMessage(),
        );

        $this->record($request, $name, $attemptNo, $failed);

        Log::warning('gateway.reconciled_not_found', [
            'gateway' => $name,
            'checkout_id' => $request->checkoutId,
            'charge_ref' => $request->chargeRef(),
            'will_failover' => $hasNext,
            'correlation_id' => Correlation::id(),
        ]);

        return $failed;
    }

    private function parkForReview(ChargeRequest $request, string $gateway): void
    {
        $checkout = Checkout::query()->find($request->checkoutId);

        if ($checkout && in_array($checkout->state, [CheckoutState::Pending, CheckoutState::Authorizing], true)) {
            try {
                $this->states->transition($checkout, CheckoutState::PendingReview, 'api', [
                    'gateway' => $gateway,
                    'charge_ref' => $request->chargeRef(),
                    'reason' => 'reconcile_inconclusive',
                ]);
            } catch (ConcurrentCheckoutTransitionException) {
                // Another actor already moved the checkout; the worker still reconciles.
            }
        }

        Redis::rpush('checkouthub:jobs:charges.reconcile', json_encode([
            'checkoutId' => $request->checkoutId,
            'gateway' => $gateway,
            'chargeRef' => $request->chargeRef(),
            'idempotencyKey' => 'reconcile-'.$request->chargeRef(),
            'correlationId' => Correlation::id(),
        ]));
    }

    private function record(ChargeRequest $request, string $gateway, int $attemptNo, ChargeResult $result): GatewayEvent
    {
        return GatewayEvent::query()->create([
            'checkout_id' => $request->checkoutId,
            'gateway' => $gateway,
            'attempt_no' => $attemptNo,
            'request_hash' => $result->requestHash,
            'response_hash' => $result->responseHash,
            'latency_ms' => $result->latencyMs,
            'outcome' => $result->outcome,
            'failover_reason' => $result->failoverReason,
            'charge_id' => $result->chargeId,
            'charge_ref' => $request->chargeRef(),
            'meta' => [
                'error_code' => $result->errorCode,
                'message' => $result->message,
                'correlation_id' => Correlation::id(),
            ],
        ]);
    }
}
