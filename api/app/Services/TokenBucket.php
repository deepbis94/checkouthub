<?php

namespace App\Services;

use App\Support\Lua;

final class TokenBucket
{
    public function allow(string $bucket, float $refillPerSecond, int $capacity, int $cost = 1): bool
    {
        $refillPerMs = $refillPerSecond / 1000;
        $nowMs = (int) floor(microtime(true) * 1000);
        $ttl = max(60000, (int) ceil(($capacity / max($refillPerSecond, 0.001)) * 1000) * 2);

        $result = Lua::eval('token_bucket', ["tb:{$bucket}"], [
            (string) $capacity,
            (string) $refillPerMs,
            (string) $nowMs,
            (string) $cost,
            (string) $ttl,
        ]);

        $allowed = is_array($result) ? (int) $result[0] : (int) $result;

        return $allowed === 1;
    }

    public function remaining(string $bucket): float
    {
        $tokens = \Illuminate\Support\Facades\Redis::hget("tb:{$bucket}", 'tokens');

        return $tokens === null ? -1 : (float) $tokens;
    }
}
