<?php

namespace App\Services;

use App\Support\Lua;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class ChargeLock
{
    public function acquire(string $checkoutId, ?int $ttlMs = null): ?string
    {
        $token = (string) Str::uuid();
        $ttl = $ttlMs ?? (int) config('checkouthub.charge_lock_ttl_ms', 15000);
        $ok = Redis::set($this->key($checkoutId), $token, 'PX', $ttl, 'NX');

        return $ok ? $token : null;
    }

    public function release(string $checkoutId, string $token): bool
    {
        return (int) Lua::eval('lock_release', [$this->key($checkoutId)], [$token]) === 1;
    }

    private function key(string $checkoutId): string
    {
        return "lock:charge:{$checkoutId}";
    }
}
