<?php

namespace App\Webhooks;

use App\Models\Checkout;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\WebhookEvent;
use Illuminate\Support\Str;

final class OrderWebhookHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Store $store, WebhookEvent $event, array $payload): void
    {
        $order = $this->extractOrder($payload);

        if ($order['provider_order_id'] === '') {
            return;
        }

        $checkoutId = $this->resolveCheckoutId($store, $payload, $order['email']);

        StoreOrder::query()->updateOrCreate(
            [
                'store_id' => $store->id,
                'provider_order_id' => $order['provider_order_id'],
            ],
            [
                'checkout_id' => $checkoutId,
                'email' => $order['email'],
                'status' => $order['status'],
                'total_minor' => $order['total_minor'],
                'currency' => $order['currency'] ?? $store->currency,
                'payload' => $payload,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{provider_order_id: string, email: ?string, status: ?string, total_minor: int, currency: ?string}
     */
    private function extractOrder(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $id = $data['id'] ?? $data['order_id'] ?? $payload['id'] ?? '';

        $total = $data['total_price'] ?? $data['total_inc_tax'] ?? $data['grand_total'] ?? $data['total'] ?? 0;
        $totalMinor = is_numeric($total) && (float) $total < 100000 && str_contains((string) $total, '.')
            ? (int) round(((float) $total) * 100)
            : (int) $total;

        if (is_string($total) && ! is_numeric($total)) {
            $totalMinor = 0;
        }

        $currency = $data['currency'] ?? $data['currency_code'] ?? null;

        return [
            'provider_order_id' => is_scalar($id) ? (string) $id : '',
            'email' => $data['email'] ?? $data['customer_email'] ?? ($data['billing_address']['email'] ?? null),
            'status' => $data['financial_status'] ?? $data['status'] ?? $data['order_status'] ?? null,
            'total_minor' => $totalMinor,
            'currency' => is_string($currency) ? $currency : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCheckoutId(Store $store, array $payload, ?string $email): ?string
    {
        $candidates = [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        foreach (['checkouthub_checkout_id', 'checkout_id'] as $key) {
            if (is_string($data[$key] ?? null) && Str::isUuid($data[$key])) {
                $candidates[] = $data[$key];
            }
        }

        foreach ($data['note_attributes'] ?? [] as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $name = strtolower((string) ($attribute['name'] ?? ''));
            $value = $attribute['value'] ?? null;
            if (in_array($name, ['checkouthub_checkout_id', 'checkout_id'], true) && is_string($value) && Str::isUuid($value)) {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $id) {
            $exists = Checkout::query()->where('store_id', $store->id)->whereKey($id)->exists();
            if ($exists) {
                return $id;
            }
        }

        return null;
    }
}
