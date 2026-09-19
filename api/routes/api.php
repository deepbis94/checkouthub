<?php

use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\GatewayEventController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InboundWebhookController;
use App\Http\Controllers\Api\V1\InternalCheckoutController;
use App\Http\Controllers\Api\V1\InternalGatewayController;
use App\Http\Controllers\Api\V1\InternalSubscriptionController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    Route::prefix('webhooks')->group(function () {
        Route::post('/shopify', [InboundWebhookController::class, 'shopify']);
        Route::post('/bigcommerce', [InboundWebhookController::class, 'bigcommerce']);
    });

    Route::middleware('store.api')->group(function () {
        Route::post('/checkouts', [CheckoutController::class, 'store']);
        Route::get('/checkouts/{checkout}', [CheckoutController::class, 'show']);
        Route::post('/checkouts/{checkout}/complete', [CheckoutController::class, 'complete']);

        Route::get('/offers', [OfferController::class, 'index']);
        Route::get('/offers/{offer}', [OfferController::class, 'show']);

        Route::get('/subscriptions', [SubscriptionController::class, 'index']);
        Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show']);

        Route::get('/gateway-events', [GatewayEventController::class, 'index']);
    });

    Route::middleware('worker')->prefix('internal')->group(function () {
        Route::post('/gateways/probe', [InternalGatewayController::class, 'probe']);
        Route::post('/gateways/{gateway}/circuit', [InternalGatewayController::class, 'circuit']);
        Route::post('/checkouts/expire', [InternalCheckoutController::class, 'expire']);
        Route::post('/checkouts/reconcile', [InternalCheckoutController::class, 'reconcile']);
        Route::post('/subscriptions/renew', [InternalSubscriptionController::class, 'renew']);
    });
});
