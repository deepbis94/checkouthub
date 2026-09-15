<?php

namespace App\Services;

use App\Enums\CheckoutState;
use App\Exceptions\CheckoutExpiredException;
use App\Exceptions\ConcurrentChargeException;
use App\Exceptions\ConcurrentCheckoutTransitionException;
use App\Exceptions\IllegalCheckoutTransitionException;
use App\Gateways\ChargeRequest;
use App\Gateways\GatewayRouter;
use App\Models\Checkout;
use App\Models\GatewayEvent;
use App\Models\Store;
use App\Models\Subscription;
use App\Domain\Checkout\CheckoutStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutCompletionService
{
    public function __construct(
        private readonly CheckoutStateMachine $states,
        private readonly GatewayRouter $gateways,
        private readonly OutboundWebhookService $webhooks,
    ) {}

    public function complete(Store $store, Checkout $checkout): Checkout
    {
        if ($checkout->store_id !== $store->id) {
            abort(404);
        }

        $checkout->refresh();

        if ($checkout->state === CheckoutState::Complete) {
            return $checkout->load(['lineItems', 'transitions', 'gatewayEvents']);
        }

        $this->expireIfNeeded($checkout);

        if ($checkout->state === CheckoutState::Expired) {
            throw new CheckoutExpiredException((string) $checkout->id);
        }

        if ($checkout->state === CheckoutState::Failed) {
            throw new IllegalCheckoutTransitionException($checkout->state->value, CheckoutState::Complete->value);
        }

        if ($checkout->state === CheckoutState::Pending) {
            try {
                $checkout = $this->states->transition($checkout, CheckoutState::Authorizing, 'api');
            } catch (ConcurrentCheckoutTransitionException) {
                $checkout->refresh();

                if ($checkout->state === CheckoutState::Complete) {
                    return $checkout->load(['lineItems', 'transitions', 'gatewayEvents']);
                }
            }
        }

        return $this->authorizeAndFinish($checkout);
    }

    private function authorizeAndFinish(Checkout $checkout): Checkout
    {
        $dueNow = (int) ($checkout->offer_snapshot['due_now_minor'] ?? $checkout->total_minor);
        $priorSuccess = GatewayEvent::query()
            ->where('checkout_id', $checkout->id)
            ->where('outcome', 'success')
            ->latest('id')
            ->first();

        try {
            if ($priorSuccess) {
                $checkout = $this->markComplete($checkout, $priorSuccess->gateway, $priorSuccess->charge_id);
            } elseif ($dueNow === 0) {
                $checkout = $this->markComplete($checkout, 'trial', null);
            } else {
                $result = $this->gateways->charge(new ChargeRequest(
                    checkoutId: $checkout->id,
                    storeId: $checkout->store_id,
                    amountMinor: $dueNow,
                    currency: $checkout->currency,
                    idempotencyKey: 'complete-'.$checkout->id,
                    customerEmail: $checkout->customer_email,
                ));

                if ($result->success) {
                    $checkout = $this->markComplete($checkout, $result->gateway, $result->chargeId);
                } else {
                    $checkout = $this->states->transition($checkout, CheckoutState::Failed, 'api', [
                        'gateway' => $result->gateway,
                        'outcome' => $result->outcome,
                        'message' => $result->message,
                    ]);
                    $checkout->loadMissing('store');
                    $this->webhooks->enqueue($checkout->store, 'checkout.failed', $checkout, 'checkout-failed-'.$checkout->id);
                }
            }
        } catch (ConcurrentChargeException $e) {
            $checkout->refresh();

            if ($checkout->state === CheckoutState::Complete) {
                return $checkout->load(['lineItems', 'transitions', 'gatewayEvents']);
            }

            throw $e;
        } catch (ConcurrentCheckoutTransitionException) {
            $checkout->refresh();
        }

        return $checkout->load(['lineItems', 'transitions', 'gatewayEvents']);
    }

    private function markComplete(Checkout $checkout, string $gateway, ?string $chargeId): Checkout
    {
        return DB::transaction(function () use ($checkout, $gateway, $chargeId) {
            $checkout = $this->states->transition($checkout, CheckoutState::Complete, 'api', [
                'gateway' => $gateway,
                'charge_id' => $chargeId,
            ]);

            $this->createSubscription($checkout, $gateway, $chargeId);
            $checkout->loadMissing('store');
            $this->webhooks->enqueue($checkout->store, 'checkout.completed', $checkout, 'checkout-completed-'.$checkout->id);

            return $checkout;
        });
    }

    private function createSubscription(Checkout $checkout, string $gateway, ?string $chargeId): Subscription
    {
        $existing = Subscription::query()->where('checkout_id', $checkout->id)->first();
        if ($existing) {
            return $existing;
        }

        $snapshot = $checkout->offer_snapshot ?? [];
        $trialDays = (int) ($snapshot['trial_days'] ?? 0);
        $interval = (string) ($snapshot['interval'] ?? 'month');
        $recurring = (int) ($snapshot['recurring_minor'] ?? $checkout->total_minor);
        $now = now();
        $trialEnds = $trialDays > 0 ? $now->copy()->addDays($trialDays) : null;
        $periodEnd = $trialEnds ?? $this->periodEnd($now, $interval);

        return Subscription::query()->create([
            'store_id' => $checkout->store_id,
            'checkout_id' => $checkout->id,
            'offer_id' => $checkout->offer_id,
            'customer_email' => $checkout->customer_email ?? 'unknown@example.com',
            'status' => $trialDays > 0 ? 'trialing' : 'active',
            'gateway' => $gateway,
            'gateway_subscription_id' => $chargeId ?? ('sub_'.Str::uuid()),
            'amount_minor' => $recurring,
            'currency' => $checkout->currency,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'next_renewal_at' => $periodEnd,
            'trial_ends_at' => $trialEnds,
        ]);
    }

    private function expireIfNeeded(Checkout $checkout): void
    {
        if ($checkout->expires_at->isPast() && in_array($checkout->state, [CheckoutState::Pending, CheckoutState::Authorizing], true)) {
            $this->states->transition($checkout, CheckoutState::Expired, 'system', ['reason' => 'ttl']);
            $checkout->refresh();
        }
    }

    private function periodEnd(\Carbon\CarbonInterface $start, string $interval): \Carbon\CarbonInterface
    {
        return $interval === 'year' ? $start->copy()->addYear() : $start->copy()->addMonth();
    }
}
