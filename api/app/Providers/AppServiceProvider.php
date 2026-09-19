<?php

namespace App\Providers;

use App\Domain\Checkout\CheckoutStateMachine;
use App\Domain\Pricing\PricingEngine;
use App\Gateways\GatewayRegistry;
use App\Gateways\GatewayRouter;
use App\Services\ChargeLock;
use App\Services\ChargeReconciliationService;
use App\Services\CheckoutCompletionService;
use App\Services\CheckoutExpiryService;
use App\Services\CheckoutIdempotency;
use App\Services\CheckoutService;
use App\Services\CircuitBreaker;
use App\Services\OutboundWebhookService;
use App\Services\SubscriptionRenewalService;
use App\Services\TokenBucket;
use App\Webhooks\WebhookIngestor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(TokenBucket::class);
        $this->app->singleton(ChargeLock::class);
        $this->app->singleton(GatewayRegistry::class);
        $this->app->singleton(GatewayRouter::class);
        $this->app->singleton(PricingEngine::class);
        $this->app->singleton(CheckoutStateMachine::class);
        $this->app->singleton(CheckoutIdempotency::class);
        $this->app->singleton(CheckoutService::class);
        $this->app->singleton(CheckoutCompletionService::class);
        $this->app->singleton(ChargeReconciliationService::class);
        $this->app->singleton(CheckoutExpiryService::class);
        $this->app->singleton(OutboundWebhookService::class);
        $this->app->singleton(SubscriptionRenewalService::class);
        $this->app->singleton(WebhookIngestor::class);
    }

    public function boot(): void
    {
        //
    }
}
