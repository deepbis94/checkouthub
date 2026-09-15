<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->header('X-Worker-Token');
        $expected = (string) config('checkouthub.worker_token');

        if (! is_string($token) || $expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['message' => 'Invalid worker token.'], 401);
        }

        return $next($request);
    }
}
