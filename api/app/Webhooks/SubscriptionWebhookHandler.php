<?php

namespace App\Webhooks;

use App\Models\Store;
use App\Models\Subscription;
use App\Models\WebhookEvent;

final class SubscriptionWebhookHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Store $store, WebhookEvent $event, array $payload): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $topic = strtolower($event->topic ?? '');
        $remoteId = $data['id'] ?? $data['admin_graphql_api_id'] ?? $data['subscription_id'] ?? null;
        $email = $data['email'] ?? $data['customer_email'] ?? ($data['customer']['email'] ?? null);

        $query = Subscription::query()->where('store_id', $store->id);

        if (is_scalar($remoteId) && (string) $remoteId !== '') {
            $found = (clone $query)->where('gateway_subscription_id', (string) $remoteId)->first();
        } else {
            $found = null;
        }

        if (! $found && is_string($email) && $email !== '') {
            $found = $query->where('customer_email', $email)->latest('id')->first();
        }

        if (! $found) {
            return;
        }

        $status = $this->statusFromTopic($topic, $data['status'] ?? null);
        $updates = ['status' => $status];

        if (is_scalar($remoteId) && $found->gateway_subscription_id === null) {
            $updates['gateway_subscription_id'] = (string) $remoteId;
        }

        if ($status === 'cancelled') {
            $updates['next_renewal_at'] = null;
        }

        $found->update($updates);
    }

    private function statusFromTopic(string $topic, mixed $payloadStatus): string
    {
        if (str_contains($topic, 'cancel') || str_contains($topic, 'expired') || str_contains($topic, 'delete')) {
            return 'cancelled';
        }

        if (str_contains($topic, 'pause')) {
            return 'paused';
        }

        if (str_contains($topic, 'fail') || str_contains($topic, 'dunning')) {
            return 'past_due';
        }

        if (is_string($payloadStatus) && $payloadStatus !== '') {
            return strtolower($payloadStatus);
        }

        return 'active';
    }
}
