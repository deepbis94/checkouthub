<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateStoreApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->header('X-Api-Key') ?: $request->bearerToken();

        if (! is_string($plain) || $plain === '') {
            return response()->json(['message' => 'Missing store API key.'], 401);
        }

        $store = Store::query()
            ->where('api_key_hash', Store::hashApiKey($plain))
            ->where('is_active', true)
            ->first();

        if (! $store) {
            return response()->json(['message' => 'Invalid store API key.'], 401);
        }

        $request->attributes->set('store', $store);
        $request->setUserResolver(fn () => $store);

        return $next($request);
    }
}
