<?php

namespace App\Services;

use App\Enums\CheckoutState;
use App\Gateways\ChargeRequest;
use App\Gateways\GatewayRouter;
use App\Models\Checkout;
use App\Models\Subscription;
use App\Domain\Checkout\CheckoutStateMachine;
use Illuminate\Support\Facades\Redis;

final class SubscriptionRenewalService
{
    public function __construct(
        private readonly GatewayRouter $gateways,
        private readonly CheckoutStateMachine $states,
        private readonly OutboundWebhookService $webhooks,
    ) {}

    /**
     * @return list<string>
     */
    public function enqueueDue(int $limit = 200): array
    {
        $ids = Subscription::query()
            ->whereIn('status', ['active', 'trialing'])
            ->where('next_renewal_at', '<=', now())
            ->orderBy('next_renewal_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            Redis::rpush('checkouthub:jobs:subscriptions.renew', json_encode([
                'subscription_id' => $id,
                'idempotencyKey' => 'renew-'.$id.'-'.now()->format('Ymd'),
            ]));
        }

        return $ids;
    }

    public function renew(string $subscriptionId): Subscription
    {
        $subscription = Subscription::query()->findOrFail($subscriptionId);
        $periodKey = 'renew-'.$subscription->id.'-'.optional($subscription->next_renewal_at)?->format('Ymd');

        if (! Redis::set("idem:renew:{$periodKey}", '1', 'EX', 86400, 'NX')) {
            return $subscription->fresh();
        }

        $store = $subscription->store;
        $checkout = Checkout::query()->create([
            'store_id' => $store->id,
            'offer_id' => $subscription->offer_id,
            'state' => CheckoutState::Pending,
            'offer_snapshot' => [
                'type' => 'renewal',
                'subscription_id' => $subscription->id,
                'due_now_minor' => $subscription->amount_minor,
                'recurring_minor' => $subscription->amount_minor,
                'currency' => $subscription->currency,
                'trial_days' => 0,
            ],
            'currency' => $subscription->currency,
            'subtotal_minor' => $subscription->amount_minor,
            'discount_minor' => 0,
            'total_minor' => $subscription->amount_minor,
            'customer_email' => $subscription->customer_email,
            'idempotency_key' => $periodKey,
            'expires_at' => now()->addHour(),
        ]);

        $this->states->recordCreated($checkout, 'worker', ['renewal' => true]);
        $checkout->refresh();
        $this->states->transition($checkout, CheckoutState::Authorizing, 'worker', ['renewal' => true]);

        $result = $this->gateways->charge(new ChargeRequest(
            checkoutId: $checkout->id,
            storeId: $store->id,
            amountMinor: $subscription->amount_minor,
            currency: $subscription->currency,
            idempotencyKey: $periodKey,
            customerEmail: $subscription->customer_email,
        ));

        if (! $result->success) {
            $this->states->transition($checkout, CheckoutState::Failed, 'worker');
            $subscription->update(['status' => 'past_due']);
            $this->webhooks->enqueue($store, 'subscription.renewal_failed', $checkout, 'renew-failed-'.$periodKey);

            return $subscription->fresh();
        }

        $this->states->transition($checkout, CheckoutState::Complete, 'worker', ['charge_id' => $result->chargeId]);
        $end = $subscription->offer?->interval === 'year' ? now()->addYear() : now()->addMonth();

        $subscription->update([
            'status' => 'active',
            'gateway' => $result->gateway,
            'gateway_subscription_id' => $result->chargeId ?? $subscription->gateway_subscription_id,
            'current_period_start' => now(),
            'current_period_end' => $end,
            'next_renewal_at' => $end,
            'trial_ends_at' => null,
        ]);

        $this->webhooks->enqueue($store, 'subscription.renewed', $checkout, 'renew-ok-'.$periodKey);

        return $subscription->fresh();
    }
}
