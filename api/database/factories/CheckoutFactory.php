<?php

namespace Database\Factories;

use App\Enums\CheckoutState;
use App\Models\Checkout;
use App\Models\Offer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkout>
 */
class CheckoutFactory extends Factory
{
    protected $model = Checkout::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'offer_id' => Offer::factory(),
            'state' => CheckoutState::Pending,
            'offer_snapshot' => [
                'plan' => 'pro_monthly',
                'amount_minor' => 1999,
                'currency' => 'USD',
                'trial_days' => 7,
            ],
            'currency' => 'USD',
            'subtotal_minor' => 1999,
            'discount_minor' => 0,
            'total_minor' => 1999,
            'customer_email' => fake()->safeEmail(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
