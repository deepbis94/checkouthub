<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfferController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $store = $this->store($request);

        $offers = Offer::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with('upsellRules')
            ->orderBy('id')
            ->get();

        return OfferResource::collection($offers);
    }

    public function show(Request $request, int $offer): OfferResource
    {
        $store = $this->store($request);

        $model = Offer::query()
            ->where('store_id', $store->id)
            ->with('upsellRules')
            ->findOrFail($offer);

        return new OfferResource($model);
    }

    private function store(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('store');

        return $store;
    }
}
