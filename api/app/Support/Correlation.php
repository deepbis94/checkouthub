<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Correlation
{
    public const HEADER = 'X-Correlation-Id';

    public static function id(): string
    {
        return (string) (request()?->attributes->get('correlation_id') ?: Str::uuid());
    }
}
