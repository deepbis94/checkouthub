<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'store_id' => Store::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'base_plan_code' => 'plan_'.Str::lower(Str::random(8)),
            'interval' => 'month',
            'amount_minor' => 1999,
            'currency' => 'USD',
            'trial_days' => 7,
            'is_active' => true,
        ];
    }
}
