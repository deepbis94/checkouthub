<?php

namespace App\Webhooks;

use App\Exceptions\InvalidWebhookSignatureException;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\OutboundWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WebhookIngestor
{
    public function __construct(
        private readonly ShopifyVerifier $shopify,
        private readonly BigCommerceVerifier $bigcommerce,
        private readonly OrderWebhookHandler $orders,
        private readonly CustomerWebhookHandler $customers,
        private readonly SubscriptionWebhookHandler $subscriptions,
        private readonly OutboundWebhookService $outbound,
    ) {}

    public function ingest(string $provider, Request $request): JsonResponse
    {
        $verifier = $this->verifier($provider);
        $raw = $request->getContent();
        $headers = $this->normalizeHeaders($request);
        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : [];

        $identifier = $verifier->storeIdentifier($headers, $payload);
        $store = Store::query()
            ->where('platform', $verifier->provider())
            ->where('platform_identifier', $identifier)
            ->where('is_active', true)
            ->first();

        if (! $store || ! is_string($store->webhook_secret) || $store->webhook_secret === '') {
            throw new InvalidWebhookSignatureException($provider);
        }

        if (! $verifier->verify($raw, $headers, $store->webhook_secret)) {
            throw new InvalidWebhookSignatureException($provider);
        }

        $eventId = $verifier->eventId($raw, $headers, $payload);
        $topic = $verifier->topic($headers, $payload);

        try {
            $event = WebhookEvent::query()->create([
                'store_id' => $store->id,
                'provider' => $verifier->provider(),
                'event_id' => $eventId,
                'topic' => $topic,
                'payload' => $payload,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json(['duplicate' => true, 'event_id' => $eventId], 200);
            }

            throw $e;
        }

        $this->dispatch($store, $event, $payload);
        $event->update(['processed_at' => now()]);

        Log::info('webhook.ingested', [
            'provider' => $verifier->provider(),
            'topic' => $topic,
            'event_id' => $eventId,
            'store_id' => $store->id,
        ]);

        return response()->json([
            'duplicate' => false,
            'event_id' => $eventId,
            'topic' => $topic,
        ], 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(Store $store, WebhookEvent $event, array $payload): void
    {
        $topic = strtolower((string) $event->topic);

        if ($this->matches($topic, ['order'])) {
            $this->orders->handle($store, $event, $payload);
        } elseif ($this->matches($topic, ['customer'])) {
            $this->customers->handle($store, $event, $payload);
        } elseif ($this->matches($topic, ['subscription'])) {
            $this->subscriptions->handle($store, $event, $payload);
        }

        $this->outbound->enqueue($store, 'platform.'.$event->provider.'.'.$event->topic, [
            'provider' => $event->provider,
            'topic' => $event->topic,
            'event_id' => $event->event_id,
            'store_id' => $store->id,
        ], 'inbound-'.$event->provider.'-'.$event->event_id);
    }

    /**
     * @param  list<string>  $needles
     */
    private function matches(string $topic, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($topic, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function verifier(string $provider): WebhookVerifier
    {
        return match ($provider) {
            'shopify' => $this->shopify,
            'bigcommerce' => $this->bigcommerce,
            default => throw new InvalidWebhookSignatureException($provider),
        };
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = $values[0] ?? '';
        }

        return $headers;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';

        return $sqlState === '23000' || str_contains($e->getMessage(), 'UNIQUE');
    }
}
