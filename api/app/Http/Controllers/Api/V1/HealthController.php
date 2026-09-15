<?php

namespace App\Http\Controllers\Api\V1;

use App\Gateways\GatewayRouter;
use App\Http\Controllers\Controller;
use App\Support\Correlation;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(GatewayRouter $router): JsonResponse
    {
        $gateways = $router->health();
        $degraded = collect($gateways)->contains(fn (array $g) => $g['state'] !== 'closed');

        return response()->json([
            'status' => $degraded ? 'degraded' : 'ok',
            'correlation_id' => Correlation::id(),
            'gateways' => $gateways,
        ]);
    }
}
