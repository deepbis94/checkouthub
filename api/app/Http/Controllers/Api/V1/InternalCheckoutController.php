<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AmbiguousGatewayException;
use App\Http\Controllers\Controller;
use App\Services\ChargeReconciliationService;
use App\Services\CheckoutExpiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalCheckoutController extends Controller
{
    public function expire(CheckoutExpiryService $expiry): JsonResponse
    {
        return response()->json(['expired' => $expiry->sweep()]);
    }

    public function reconcile(Request $request, ChargeReconciliationService $reconciliation): JsonResponse
    {
        $validated = $request->validate([
            'checkout_id' => ['required', 'string'],
            'gateway' => ['nullable', 'string'],
            'charge_ref' => ['nullable', 'string'],
        ]);

        try {
            return response()->json($reconciliation->resolve(
                $validated['checkout_id'],
                $validated['gateway'] ?? null,
                $validated['charge_ref'] ?? null,
            ));
        } catch (AmbiguousGatewayException $e) {
            return response()->json([
                'status' => 'inconclusive',
                'retry' => true,
                'message' => $e->getMessage(),
            ], 503);
        }
    }
}
