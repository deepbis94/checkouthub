<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

final class Lua
{
    /**
     * @param  array<int, string>  $keys
     * @param  array<int, string|int|float>  $args
     */
    public static function eval(string $scriptName, array $keys, array $args): mixed
    {
        $path = resource_path("lua/{$scriptName}.lua");
        $script = file_get_contents($path);

        if ($script === false) {
            throw new \RuntimeException("Lua script not found: {$path}");
        }

        return Redis::eval($script, count($keys), ...$keys, ...$args);
    }
}
