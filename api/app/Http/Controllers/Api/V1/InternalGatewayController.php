<?php

namespace App\Http\Controllers\Api\V1;

use App\Gateways\GatewayRouter;
use App\Http\Controllers\Controller;
use App\Services\CircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalGatewayController extends Controller
{
    public function probe(GatewayRouter $router): JsonResponse
    {
        return response()->json(['data' => $router->probeAll()]);
    }

    public function circuit(Request $request, string $gateway, CircuitBreaker $breaker): JsonResponse
    {
        $state = $request->string('state')->toString();

        match ($state) {
            'open' => $breaker->forceOpen($gateway),
            'closed' => $breaker->forceClosed($gateway),
            default => $breaker->reset($gateway),
        };

        return response()->json(['data' => $breaker->snapshot($gateway)]);
    }
}
