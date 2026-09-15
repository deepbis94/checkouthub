<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'gateway' => $this->gateway,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'customer_email' => $this->customer_email,
            'checkout_id' => $this->checkout_id,
            'offer_id' => $this->offer_id,
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'next_renewal_at' => $this->next_renewal_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
        ];
    }
}
