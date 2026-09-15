<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Services\SubscriptionRenewalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalSubscriptionController extends Controller
{
    public function renew(Request $request, SubscriptionRenewalService $renewals): JsonResponse
    {
        $subscriptionId = $request->input('subscription_id');

        if (is_string($subscriptionId) && $subscriptionId !== '') {
            $subscription = $renewals->renew($subscriptionId);

            return (new SubscriptionResource($subscription))->response();
        }

        return response()->json(['enqueued' => $renewals->enqueueDue()]);
    }
}
