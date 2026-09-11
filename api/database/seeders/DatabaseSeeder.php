<?php

namespace Database\Seeders;

use App\Models\DiscountCode;
use App\Models\Offer;
use App\Models\Store;
use App\Models\UpsellRule;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public const DEMO_API_KEY = 'ch_live_demo_a1b2c3d4e5f6';

    public const SHOPIFY_WEBHOOK_SECRET = 'whsec_demo_shopify';

    public const BIGCOMMERCE_WEBHOOK_SECRET = 'whsec_demo_bigcommerce';

    public function run(): void
    {
        $store = Store::query()->updateOrCreate(
            ['api_key_hash' => Store::hashApiKey(self::DEMO_API_KEY)],
            [
                'name' => 'Demo Shopify Store',
                'platform' => 'shopify',
                'platform_identifier' => 'demo-shop.myshopify.com',
                'api_key_prefix' => substr(self::DEMO_API_KEY, 0, 12),
                'currency' => 'USD',
                'webhook_secret' => self::SHOPIFY_WEBHOOK_SECRET,
                'outbound_webhook_url' => 'http://storefront/webhooks/checkouthub',
                'settings' => ['timezone' => 'UTC'],
                'is_active' => true,
            ]
        );

        $offer = Offer::query()->updateOrCreate(
            ['store_id' => $store->id, 'slug' => 'pro-monthly'],
            [
                'name' => 'Pro Monthly',
                'base_plan_code' => 'plan_pro_monthly',
                'interval' => 'month',
                'amount_minor' => 1999,
                'currency' => 'USD',
                'trial_days' => 7,
                'is_active' => true,
            ]
        );

        UpsellRule::query()->updateOrCreate(
            ['offer_id' => $offer->id, 'name' => 'Rush shipping warranty'],
            [
                'trigger_conditions' => ['on' => 'checkout_created'],
                'eligibility_rules' => ['min_total_minor' => 1000],
                'priority' => 10,
                'display_slot' => 'post_purchase',
                'amount_minor' => 499,
                'is_stackable' => true,
                'is_active' => true,
            ]
        );

        DiscountCode::query()->updateOrCreate(
            ['store_id' => $store->id, 'code' => 'SAVE20'],
            [
                'type' => 'percent',
                'value' => 20,
                'currency' => 'USD',
                'is_active' => true,
            ]
        );

        Store::query()->updateOrCreate(
            ['platform' => 'bigcommerce', 'platform_identifier' => 'abcde'],
            [
                'name' => 'Demo BigCommerce Store',
                'api_key_prefix' => 'ch_bc_demo_',
                'api_key_hash' => Store::hashApiKey('ch_live_demo_bigcommerce'),
                'currency' => 'USD',
                'webhook_secret' => self::BIGCOMMERCE_WEBHOOK_SECRET,
                'outbound_webhook_url' => 'http://storefront/webhooks/checkouthub',
                'settings' => ['timezone' => 'UTC'],
                'is_active' => true,
            ]
        );
    }
}
