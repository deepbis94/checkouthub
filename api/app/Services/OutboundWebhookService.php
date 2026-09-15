<?php

namespace App\Services;

use App\Models\Checkout;
use App\Models\OutboundWebhook;
use App\Models\Store;
use App\Support\Correlation;
use Illuminate\Support\Facades\Redis;

final class OutboundWebhookService
{
    /**
     * @param  array<string, mixed>|Checkout  $source
     */
    public function enqueue(Store $store, string $eventType, array|Checkout $source, string $idempotencyKey): OutboundWebhook
    {
        $payload = $source instanceof Checkout
            ? [
                'event' => $eventType,
                'checkout_id' => $source->id,
                'store_id' => $store->id,
                'state' => $source->state->value,
                'total_minor' => $source->total_minor,
                'currency' => $source->currency,
                'correlation_id' => Correlation::id(),
            ]
            : $source;

        $webhook = OutboundWebhook::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'store_id' => $store->id,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'pending',
            ],
        );

        if ($webhook->wasRecentlyCreated && $store->outbound_webhook_url) {
            $this->push($store, $webhook, $idempotencyKey);
        }

        return $webhook;
    }

    private function push(Store $store, OutboundWebhook $webhook, string $idempotencyKey): void
    {
        $body = json_encode($webhook->payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = base64_encode(hash_hmac('sha256', $body, (string) $store->webhook_secret, true));

        Redis::rpush('checkouthub:jobs:webhooks.outbound', json_encode([
            'url' => $store->outbound_webhook_url,
            'payload' => $webhook->payload,
            'idempotencyKey' => $idempotencyKey,
            'correlationId' => Correlation::id(),
            'signature' => $signature,
        ]));
    }
}
