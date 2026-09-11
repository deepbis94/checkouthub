<?php

namespace Database\Factories;

use App\Models\DiscountCode;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountCode>
 */
class DiscountCodeFactory extends Factory
{
    protected $model = DiscountCode::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'code' => strtoupper(fake()->bothify('SAVE##')),
            'type' => 'percent',
            'value' => 20,
            'currency' => 'USD',
            'is_active' => true,
        ];
    }
}
