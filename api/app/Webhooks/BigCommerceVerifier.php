<?php

namespace App\Webhooks;

final class BigCommerceVerifier implements WebhookVerifier
{
    public function provider(): string
    {
        return 'bigcommerce';
    }

    public function verify(string $rawBody, array $headers, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $header = $this->header($headers, 'X-BC-Webhook-Signature')
            ?? $this->header($headers, 'X-Bigcommerce-Webhook-Signature');

        if (is_string($header) && $header !== '') {
            $binary = hash_hmac('sha256', $rawBody, $secret, true);
            $base64 = base64_encode($binary);
            $hex = hash_hmac('sha256', $rawBody, $secret);

            return hash_equals($base64, $header) || hash_equals($hex, strtolower($header));
        }

        return $this->verifySignedPayload($rawBody, $secret);
    }

    public function eventId(string $rawBody, array $headers, array $payload): string
    {
        $headerId = $this->header($headers, 'X-BC-Webhook-Id');
        if ($headerId) {
            return $headerId;
        }

        if (is_string($payload['hash'] ?? null) && $payload['hash'] !== '') {
            return $payload['hash'];
        }

        return hash('sha256', $rawBody);
    }

    public function topic(array $headers, array $payload): string
    {
        return (string) ($payload['scope'] ?? $this->header($headers, 'X-BC-Topic') ?? 'unknown');
    }

    public function storeIdentifier(array $headers, array $payload): ?string
    {
        $producer = (string) ($payload['producer'] ?? '');
        if (str_starts_with($producer, 'stores/')) {
            return substr($producer, strlen('stores/'));
        }

        $storeId = $payload['store_id'] ?? $payload['storeId'] ?? null;

        return is_scalar($storeId) ? (string) $storeId : null;
    }

    private function verifySignedPayload(string $rawBody, string $secret): bool
    {
        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded)) {
            return false;
        }

        $signed = $decoded['signed_payload'] ?? $decoded['signedPayload'] ?? null;
        if (! is_string($signed) || ! str_contains($signed, '.')) {
            return false;
        }

        [$payload, $signature] = explode('.', $signed, 2);
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
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
