<?php

namespace App\Domain\Checkout;

use App\Enums\CheckoutState;
use App\Exceptions\ConcurrentCheckoutTransitionException;
use App\Exceptions\IllegalCheckoutTransitionException;
use App\Models\Checkout;
use App\Models\CheckoutTransition;
use App\Support\Correlation;

final class CheckoutStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        CheckoutState::Pending->value => [
            CheckoutState::Authorizing->value,
            CheckoutState::Expired->value,
            CheckoutState::Failed->value,
            CheckoutState::PendingReview->value,
        ],
        CheckoutState::Authorizing->value => [
            CheckoutState::Complete->value,
            CheckoutState::Failed->value,
            CheckoutState::Expired->value,
            CheckoutState::PendingReview->value,
        ],
        CheckoutState::PendingReview->value => [
            CheckoutState::Complete->value,
            CheckoutState::Failed->value,
            CheckoutState::Expired->value,
        ],
        CheckoutState::Complete->value => [],
        CheckoutState::Failed->value => [],
        CheckoutState::Expired->value => [],
    ];

    public function canTransition(CheckoutState $from, CheckoutState $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertCanTransition(CheckoutState $from, CheckoutState $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new IllegalCheckoutTransitionException($from->value, $to->value);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function transition(Checkout $checkout, CheckoutState $to, string $actor, array $meta = []): Checkout
    {
        $from = $checkout->state;
        $this->assertCanTransition($from, $to);

        $values = ['state' => $to->value];

        if ($to === CheckoutState::Complete) {
            $values['completed_at'] = now();
        }

        $affected = Checkout::query()
            ->whereKey($checkout->id)
            ->where('state', $from->value)
            ->update($values);

        if ($affected !== 1) {
            throw new ConcurrentCheckoutTransitionException((string) $checkout->id);
        }

        CheckoutTransition::query()->create([
            'checkout_id' => $checkout->id,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'actor' => $actor,
            'meta' => array_merge($meta, ['correlation_id' => Correlation::id()]),
            'created_at' => now(),
        ]);

        return $checkout->refresh();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function recordCreated(Checkout $checkout, string $actor, array $meta = []): void
    {
        CheckoutTransition::query()->create([
            'checkout_id' => $checkout->id,
            'from_state' => null,
            'to_state' => CheckoutState::Pending->value,
            'actor' => $actor,
            'meta' => array_merge($meta, ['correlation_id' => Correlation::id()]),
            'created_at' => now(),
        ]);
    }
}
