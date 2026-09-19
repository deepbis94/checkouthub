<?php

use App\Exceptions\AmbiguousChargeException;
use App\Exceptions\CheckoutExpiredException;
use App\Exceptions\ConcurrentChargeException;
use App\Exceptions\ConcurrentCheckoutTransitionException;
use App\Exceptions\IllegalCheckoutTransitionException;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Http\Middleware\AuthenticateStoreApiKey;
use App\Http\Middleware\AuthenticateWorker;
use App\Http\Middleware\CorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(CorrelationId::class);
        $middleware->alias([
            'store.api' => AuthenticateStoreApiKey::class,
            'worker' => AuthenticateWorker::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(fn (InvalidWebhookSignatureException $e) => response()->json(['message' => $e->getMessage()], 401));
        $exceptions->render(fn (CheckoutExpiredException $e) => response()->json(['message' => $e->getMessage()], 410));
        $exceptions->render(fn (IllegalCheckoutTransitionException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (ConcurrentChargeException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (ConcurrentCheckoutTransitionException $e) => response()->json(['message' => $e->getMessage()], 409));
        $exceptions->render(fn (AmbiguousChargeException $e) => response()->json([
            'message' => $e->getMessage(),
            'checkout_id' => $e->checkoutId,
            'state' => 'pending_review',
        ], 409));
    })->create();
