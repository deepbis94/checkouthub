<?php

namespace App\Console\Commands;

use App\Enums\CheckoutState;
use App\Gateways\ChargeRequest;
use App\Gateways\GatewayRouter;
use App\Models\Checkout;
use App\Models\GatewayEvent;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TryChargeCommand extends Command
{
    protected $signature = 'checkouthub:try-charge {--email=buyer@example.com}';

    protected $description = 'Create a throwaway checkout and run the gateway failover charge flow.';

    public function handle(GatewayRouter $router): int
    {
        $store = Store::query()->first();

        if (! $store) {
            $this->error('No store found. Run php artisan db:seed');

            return self::FAILURE;
        }

        $offer = $store->offers()->first();
        $checkout = Checkout::query()->create([
            'store_id' => $store->id,
            'offer_id' => $offer?->id,
            'state' => CheckoutState::Authorizing,
            'offer_snapshot' => [
                'plan' => $offer?->base_plan_code,
                'amount_minor' => $offer?->amount_minor ?? 1999,
                'currency' => $store->currency,
            ],
            'currency' => $store->currency,
            'subtotal_minor' => $offer?->amount_minor ?? 1999,
            'total_minor' => $offer?->amount_minor ?? 1999,
            'customer_email' => (string) $this->option('email'),
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $result = $router->charge(new ChargeRequest(
            checkoutId: $checkout->id,
            storeId: $store->id,
            amountMinor: $checkout->total_minor,
            currency: $checkout->currency,
            idempotencyKey: 'try-'.$checkout->id,
            customerEmail: $checkout->customer_email,
        ));

        $this->info(json_encode([
            'checkout_id' => $checkout->id,
            'success' => $result->success,
            'gateway' => $result->gateway,
            'charge_id' => $result->chargeId,
            'outcome' => $result->outcome,
            'events' => GatewayEvent::query()
                ->where('checkout_id', $checkout->id)
                ->orderBy('id')
                ->get(['gateway', 'attempt_no', 'outcome', 'failover_reason', 'latency_ms', 'charge_id']),
        ], JSON_PRETTY_PRINT));

        return $result->success ? self::SUCCESS : self::FAILURE;
    }
}
