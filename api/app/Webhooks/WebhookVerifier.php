<?php

namespace App\Webhooks;

interface WebhookVerifier
{
    public function provider(): string;

    public function verify(string $rawBody, array $headers, string $secret): bool;

    public function eventId(string $rawBody, array $headers, array $payload): string;

    public function topic(array $headers, array $payload): string;

    public function storeIdentifier(array $headers, array $payload): ?string;
}
