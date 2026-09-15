<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Store;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $store = $this->store($request);

        $subs = Subscription::query()
            ->where('store_id', $store->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return SubscriptionResource::collection($subs);
    }

    public function show(Request $request, string $subscription): SubscriptionResource
    {
        $store = $this->store($request);

        $model = Subscription::query()
            ->where('store_id', $store->id)
            ->findOrFail($subscription);

        return new SubscriptionResource($model);
    }

    private function store(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('store');

        return $store;
    }
}
