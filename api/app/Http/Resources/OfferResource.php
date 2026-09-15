<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'base_plan_code' => $this->base_plan_code,
            'interval' => $this->interval,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'trial_days' => $this->trial_days,
            'is_active' => $this->is_active,
            'upsell_rules' => $this->whenLoaded('upsellRules', fn () => $this->upsellRules->map(fn ($rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'display_slot' => $rule->display_slot,
                'amount_minor' => $rule->amount_minor,
                'is_stackable' => $rule->is_stackable,
                'trigger_conditions' => $rule->trigger_conditions,
                'eligibility_rules' => $rule->eligibility_rules,
            ])->values()),
        ];
    }
}
