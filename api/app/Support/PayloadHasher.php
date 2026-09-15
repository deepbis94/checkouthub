<?php

namespace App\Support;

final class PayloadHasher
{
    /**
     * @param  array<string, mixed>|string  $payload
     */
    public static function hash(array|string $payload): string
    {
        $canonical = is_string($payload)
            ? $payload
            : (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return hash('sha256', $canonical);
    }
}
