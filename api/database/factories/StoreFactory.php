<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $plain = 'ch_test_'.Str::lower(Str::random(24));

        return [
            'name' => fake()->company(),
            'platform' => fake()->randomElement(['shopify', 'bigcommerce']),
            'platform_identifier' => fake()->unique()->domainName(),
            'api_key_prefix' => substr($plain, 0, 12),
            'api_key_hash' => Store::hashApiKey($plain),
            'currency' => 'USD',
            'webhook_secret' => Str::random(32),
            'outbound_webhook_url' => fake()->url(),
            'settings' => [],
            'is_active' => true,
        ];
    }
}
