<?php

namespace App\Services;

use App\Models\Checkout;
use App\Models\Store;
use Illuminate\Support\Facades\Redis;

final class CheckoutIdempotency
{
    public function find(Store $store, string $key): ?Checkout
    {
        $cached = Redis::get($this->redisKey($store->id, $key));

        if (is_string($cached) && $cached !== '') {
            $checkout = Checkout::query()
                ->where('store_id', $store->id)
                ->whereKey($cached)
                ->first();

            if ($checkout) {
                return $checkout;
            }
        }

        return Checkout::query()
            ->where('store_id', $store->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function remember(Store $store, string $key, Checkout $checkout, int $ttlSeconds): void
    {
        Redis::set($this->redisKey($store->id, $key), $checkout->id, 'EX', $ttlSeconds);
    }

    private function redisKey(int $storeId, string $key): string
    {
        return "idem:checkout:{$storeId}:{$key}";
    }
}
