<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CheckoutExpiryService;
use Illuminate\Http\JsonResponse;

class InternalCheckoutController extends Controller
{
    public function expire(CheckoutExpiryService $expiry): JsonResponse
    {
        return response()->json(['expired' => $expiry->sweep()]);
    }
}
