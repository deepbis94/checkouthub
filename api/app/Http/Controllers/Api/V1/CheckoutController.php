<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckoutRequest;
use App\Http\Resources\CheckoutResource;
use App\Models\Store;
use App\Services\CheckoutCompletionService;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function store(StoreCheckoutRequest $request, CheckoutService $checkouts): JsonResponse
    {
        $checkout = $checkouts->create(
            $this->storeModel($request),
            $request->validated(),
            $request->header('Idempotency-Key'),
        );

        return (new CheckoutResource($checkout))
            ->response()
            ->setStatusCode($checkout->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $checkout, CheckoutService $checkouts): CheckoutResource
    {
        return new CheckoutResource($checkouts->show($this->storeModel($request), $checkout));
    }

    public function complete(
        Request $request,
        string $checkout,
        CheckoutService $checkouts,
        CheckoutCompletionService $completion,
    ): CheckoutResource {
        $store = $this->storeModel($request);
        $model = $checkouts->show($store, $checkout);

        return new CheckoutResource($completion->complete($store, $model));
    }

    private function storeModel(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('store');

        return $store;
    }
}
