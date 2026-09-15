<?php

namespace App\Webhooks;

use App\Models\Customer;
use App\Models\Store;
use App\Models\WebhookEvent;

final class CustomerWebhookHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Store $store, WebhookEvent $event, array $payload): void
    {
        $customer = $this->extractCustomer($payload);

        if ($customer['provider_customer_id'] === '') {
            return;
        }

        Customer::query()->updateOrCreate(
            [
                'store_id' => $store->id,
                'provider_customer_id' => $customer['provider_customer_id'],
            ],
            [
                'email' => $customer['email'],
                'name' => $customer['name'],
                'payload' => $payload,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{provider_customer_id: string, email: ?string, name: ?string}
     */
    private function extractCustomer(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $id = $data['id'] ?? $data['customer_id'] ?? $payload['id'] ?? '';
        $first = $data['first_name'] ?? $data['firstName'] ?? '';
        $last = $data['last_name'] ?? $data['lastName'] ?? '';
        $name = trim($first.' '.$last);
        if ($name === '') {
            $name = $data['name'] ?? null;
        }

        return [
            'provider_customer_id' => is_scalar($id) ? (string) $id : '',
            'email' => isset($data['email']) && is_string($data['email']) ? $data['email'] : null,
            'name' => is_string($name) && $name !== '' ? $name : null,
        ];
    }
}
