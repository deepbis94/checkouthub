<?php

namespace App\Providers;

use App\Gateways\GatewayRegistry;
use App\Gateways\GatewayRouter;
use App\Services\ChargeLock;
use App\Services\CircuitBreaker;
use App\Services\TokenBucket;
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
        $this->app->singleton(\App\Domain\Pricing\PricingEngine::class);
        $this->app->singleton(\App\Domain\Checkout\CheckoutStateMachine::class);
        $this->app->singleton(\App\Services\CheckoutIdempotency::class);
        $this->app->singleton(\App\Services\CheckoutService::class);
        $this->app->singleton(\App\Services\CheckoutCompletionService::class);
        $this->app->singleton(\App\Services\CheckoutExpiryService::class);
        $this->app->singleton(\App\Services\OutboundWebhookService::class);
        $this->app->singleton(\App\Services\SubscriptionRenewalService::class);
        $this->app->singleton(\App\Webhooks\WebhookIngestor::class);
    }

    public function boot(): void
    {
        //
    }
}
