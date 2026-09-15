<?php

namespace App\Domain\Pricing;

use App\Models\DiscountCode;
use App\Models\Offer;
use App\Models\Store;
use App\Models\UpsellRule;
use InvalidArgumentException;

final class PricingEngine
{
    /**
     * @param  list<int>  $upsellIds
     * @param  array{proration_days_remaining?: int, proration_period_days?: int, customer_email?: ?string}  $options
     */
    public function quote(Store $store, Offer $offer, array $upsellIds = [], ?string $discountCode = null, array $options = []): PricingQuote
    {
        if ($offer->store_id !== $store->id || ! $offer->is_active) {
            throw new InvalidArgumentException('Offer is not available for this store.');
        }

        $currency = $store->currency;
        $lineItems = [];
        $subtotal = 0;

        $lineItems[] = $this->line('plan', $offer->base_plan_code, $offer->name, $offer->amount_minor);
        $subtotal += $offer->amount_minor;

        $appliedNonStackable = false;
        $rules = $offer->upsellRules()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $requested = in_array($rule->id, $upsellIds, true);
            $auto = ($rule->trigger_conditions['on'] ?? null) === 'checkout_created';

            if (! $requested && ! $auto) {
                continue;
            }

            if (! $this->eligible($rule, $subtotal, $options['customer_email'] ?? null)) {
                continue;
            }

            if (! $rule->is_stackable && $appliedNonStackable) {
                continue;
            }

            if (! $rule->is_stackable) {
                $appliedNonStackable = true;
            }

            $lineItems[] = $this->line('upsell', 'upsell_'.$rule->id, $rule->name, $rule->amount_minor, [
                'rule_id' => $rule->id,
                'display_slot' => $rule->display_slot,
                'priority' => $rule->priority,
            ]);
            $subtotal += $rule->amount_minor;
        }

        $discountMinor = 0;
        $discountMeta = null;

        if (is_string($discountCode) && $discountCode !== '') {
            $code = DiscountCode::query()
                ->where('store_id', $store->id)
                ->whereRaw('LOWER(code) = ?', [strtolower($discountCode)])
                ->where('is_active', true)
                ->first();

            if (! $code) {
                throw new InvalidArgumentException("Unknown discount code [{$discountCode}].");
            }

            $discountMinor = $code->type === 'percent'
                ? (int) floor($subtotal * $code->value / 100)
                : min($subtotal, $code->value);

            $discountMeta = [
                'code' => $code->code,
                'type' => $code->type,
                'value' => $code->value,
            ];

            $lineItems[] = $this->line('discount', $code->code, 'Discount '.$code->code, -$discountMinor);
        }

        $recurring = max(0, $subtotal - $discountMinor);
        $trialDays = (int) $offer->trial_days;
        $prorationMinor = 0;

        $remaining = (int) ($options['proration_days_remaining'] ?? 0);
        if ($trialDays === 0 && $remaining > 0) {
            $period = (int) ($options['proration_period_days'] ?? ($offer->interval === 'year' ? 365 : 30));
            $period = max(1, $period);
            $prorationMinor = (int) floor($offer->amount_minor * min($remaining, $period) / $period);
            $lineItems[] = $this->line('proration', 'proration_credit', 'Proration credit', -$prorationMinor);
        }

        $dueNow = $trialDays > 0 ? 0 : max(0, $recurring - $prorationMinor);

        $snapshot = [
            'offer_id' => $offer->id,
            'name' => $offer->name,
            'base_plan_code' => $offer->base_plan_code,
            'interval' => $offer->interval,
            'trial_days' => $trialDays,
            'currency' => $currency,
            'line_items' => $lineItems,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discountMinor,
            'discount' => $discountMeta,
            'proration_minor' => $prorationMinor,
            'recurring_minor' => $recurring,
            'due_now_minor' => $dueNow,
            'priced_at' => now()->toIso8601String(),
        ];

        return new PricingQuote(
            currency: $currency,
            subtotalMinor: $subtotal,
            discountMinor: $discountMinor,
            prorationMinor: $prorationMinor,
            recurringMinor: $recurring,
            dueNowMinor: $dueNow,
            trialDays: $trialDays,
            lineItems: $lineItems,
            snapshot: $snapshot,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function line(string $type, string $sku, string $name, int $amountMinor, array $meta = []): array
    {
        return [
            'type' => $type,
            'sku' => $sku,
            'name' => $name,
            'quantity' => 1,
            'unit_amount_minor' => $amountMinor,
            'amount_minor' => $amountMinor,
            'meta' => $meta,
        ];
    }

    private function eligible(UpsellRule $rule, int $currentSubtotal, ?string $email): bool
    {
        $eligibility = $rule->eligibility_rules ?? [];
        $min = $eligibility['min_total_minor'] ?? null;

        if (is_numeric($min) && $currentSubtotal < (int) $min) {
            return false;
        }

        $domain = $eligibility['email_domain'] ?? null;
        if (is_string($domain) && $email && ! str_ends_with(strtolower($email), '@'.strtolower($domain))) {
            return false;
        }

        return true;
    }
}
