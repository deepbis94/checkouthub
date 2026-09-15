<?php

namespace App\Services;

use App\Enums\CheckoutState;
use App\Models\Checkout;
use App\Domain\Checkout\CheckoutStateMachine;

final class CheckoutExpiryService
{
    public function __construct(private readonly CheckoutStateMachine $states) {}

    /**
     * @return list<string>
     */
    public function sweep(int $limit = 100): array
    {
        $ids = [];

        $stale = Checkout::query()
            ->whereIn('state', [CheckoutState::Pending->value, CheckoutState::Authorizing->value])
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        foreach ($stale as $checkout) {
            try {
                $this->states->transition($checkout, CheckoutState::Expired, 'worker', ['reason' => 'expiry_sweep']);
                $ids[] = $checkout->id;
            } catch (\Throwable) {
                continue;
            }
        }

        return $ids;
    }
}
