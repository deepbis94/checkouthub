<?php

namespace App\Http\Resources;

use App\Models\Checkout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Checkout */
class CheckoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_minor' => $this->discount_minor,
            'total_minor' => $this->total_minor,
            'due_now_minor' => $this->offer_snapshot['due_now_minor'] ?? $this->total_minor,
            'recurring_minor' => $this->offer_snapshot['recurring_minor'] ?? $this->total_minor,
            'discount_code' => $this->discount_code,
            'customer_email' => $this->customer_email,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expires_in' => max(0, ($this->expires_at?->getTimestamp() ?? 0) - now()->getTimestamp()),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'offer_snapshot' => $this->offer_snapshot,
            'line_items' => $this->whenLoaded('lineItems', fn () => $this->lineItems->map(fn ($item) => [
                'sku' => $item->sku,
                'name' => $item->name,
                'type' => $item->type,
                'quantity' => $item->quantity,
                'unit_amount_minor' => $item->unit_amount_minor,
                'amount_minor' => $item->amount_minor,
            ])->values()),
            'transitions' => $this->whenLoaded('transitions', fn () => $this->transitions->map(fn ($t) => [
                'from_state' => $t->from_state,
                'to_state' => $t->to_state,
                'actor' => $t->actor,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
