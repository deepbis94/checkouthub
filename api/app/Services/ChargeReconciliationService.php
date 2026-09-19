<?php

namespace App\Services;

use App\Contracts\GatewayAdapter;
use App\Domain\Checkout\CheckoutStateMachine;
use App\Enums\CheckoutState;
use App\Enums\GatewayOutcome;
use App\Exceptions\AmbiguousGatewayException;
use App\Gateways\ChargeResult;
use App\Gateways\GatewayRegistry;
use App\Models\Checkout;
use App\Models\GatewayEvent;
use App\Support\Correlation;
use App\Support\PayloadHasher;
use Illuminate\Support\Facades\Log;

final class ChargeReconciliationService
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly CheckoutStateMachine $states,
        private readonly CheckoutCompletionService $completion,
        private readonly OutboundWebhookService $webhooks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $checkoutId, ?string $gateway = null, ?string $chargeRef = null): array
    {
        $checkout = Checkout::query()->findOrFail($checkoutId);

        if ($checkout->state === CheckoutState::Complete) {
            return ['status' => 'already_complete', 'checkout_id' => $checkout->id];
        }

        if ($checkout->state === CheckoutState::Failed) {
            return ['status' => 'already_failed', 'checkout_id' => $checkout->id];
        }

        if ($checkout->state !== CheckoutState::PendingReview) {
            return ['status' => 'skipped', 'state' => $checkout->state->value, 'checkout_id' => $checkout->id];
        }

        $hint = GatewayEvent::query()
            ->where('checkout_id', $checkout->id)
            ->whereNotNull('charge_ref')
            ->latest('id')
            ->first();

        $gateway ??= $hint?->gateway ?? 'stripe';
        $chargeRef ??= $hint?->charge_ref ?? 'ch_'.$checkout->id;
        $adapter = $this->registry->get($gateway);

        try {
            $found = $adapter->fetchCharge($chargeRef);
        } catch (AmbiguousGatewayException $e) {
            Log::warning('gateway.worker_reconcile_inconclusive', [
                'gateway' => $gateway,
                'checkout_id' => $checkout->id,
                'charge_ref' => $chargeRef,
                'reason' => $e->reason,
            ]);

            throw $e;
        }

        if ($found?->success) {
            $alreadySucceeded = GatewayEvent::query()
                ->where('checkout_id', $checkout->id)
                ->where('outcome', GatewayOutcome::Success->value)
                ->exists();

            if (! $alreadySucceeded) {
                $this->record($checkout, $adapter, $chargeRef, $found, GatewayOutcome::Success, 'reconciled_after_timeout');
            }

            $checkout->loadMissing('store');
            $this->completion->complete($checkout->store, $checkout->fresh());

            return ['status' => 'completed', 'checkout_id' => $checkout->id, 'charge_id' => $found->chargeId];
        }

        $this->record(
            $checkout,
            $adapter,
            $chargeRef,
            $found,
            GatewayOutcome::HardFailure,
            'reconciled_not_found',
        );

        if (in_array($checkout->fresh()->state, [CheckoutState::PendingReview], true)) {
            $this->states->transition($checkout->fresh(), CheckoutState::Failed, 'worker', [
                'gateway' => $gateway,
                'charge_ref' => $chargeRef,
                'reason' => 'reconciled_not_found',
            ]);
            $checkout->loadMissing('store');
            $this->webhooks->enqueue($checkout->store, 'checkout.failed', $checkout->fresh(), 'checkout-failed-'.$checkout->id);
        }

        return ['status' => 'failed', 'checkout_id' => $checkout->id];
    }

    private function record(
        Checkout $checkout,
        GatewayAdapter $adapter,
        string $chargeRef,
        ?ChargeResult $found,
        GatewayOutcome $outcome,
        string $reason,
    ): void {
        $attemptNo = (int) GatewayEvent::query()->where('checkout_id', $checkout->id)->max('attempt_no');

        GatewayEvent::query()->create([
            'checkout_id' => $checkout->id,
            'gateway' => $adapter->name(),
            'attempt_no' => max(1, $attemptNo),
            'request_hash' => $found?->requestHash ?: PayloadHasher::hash(['op' => 'fetch', 'charge_ref' => $chargeRef]),
            'response_hash' => $found?->responseHash ?: PayloadHasher::hash(['status' => $reason]),
            'latency_ms' => $found?->latencyMs ?? 0,
            'outcome' => $outcome->value,
            'failover_reason' => $reason,
            'charge_id' => $found?->chargeId,
            'charge_ref' => $chargeRef,
            'meta' => [
                'source' => 'worker',
                'correlation_id' => Correlation::id(),
            ],
        ]);
    }
}
