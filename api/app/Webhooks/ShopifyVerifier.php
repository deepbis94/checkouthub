<?php

namespace App\Webhooks;

final class ShopifyVerifier implements WebhookVerifier
{
    public function provider(): string
    {
        return 'shopify';
    }

    public function verify(string $rawBody, array $headers, string $secret): bool
    {
        $header = $this->header($headers, 'X-Shopify-Hmac-Sha256');

        if ($header === null || $secret === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $header);
    }

    public function eventId(string $rawBody, array $headers, array $payload): string
    {
        return $this->header($headers, 'X-Shopify-Event-Id')
            ?? $this->header($headers, 'X-Shopify-Webhook-Id')
            ?? hash('sha256', $rawBody);
    }

    public function topic(array $headers, array $payload): string
    {
        return $this->header($headers, 'X-Shopify-Topic') ?? 'unknown';
    }

    public function storeIdentifier(array $headers, array $payload): ?string
    {
        return $this->header($headers, 'X-Shopify-Shop-Domain');
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $resolved = is_array($value) ? ($value[0] ?? null) : $value;

                return is_string($resolved) && $resolved !== '' ? $resolved : null;
            }
        }

        return null;
    }
}
