<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GatewayEvent;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Store $store */
        $store = $request->attributes->get('store');

        $events = GatewayEvent::query()
            ->whereHas('checkout', fn ($q) => $q->where('store_id', $store->id))
            ->when($request->string('checkout_id')->toString(), fn ($q, $id) => $q->where('checkout_id', $id))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $events]);
    }
}
