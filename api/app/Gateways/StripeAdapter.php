<?php

namespace App\Gateways;

use App\Contracts\GatewayAdapter;
use App\Exceptions\AmbiguousGatewayException;
use App\Exceptions\HardGatewayException;
use App\Support\PayloadHasher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class StripeAdapter implements GatewayAdapter
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function priority(): int
    {
        return (int) ($this->config['priority'] ?? 1);
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $started = hrtime(true);
        $requestHash = PayloadHasher::hash($request->toHashableArray() + ['gateway' => $this->name()]);

        if (($this->config['mode'] ?? 'simulate') === 'simulate') {
            return $this->simulate($request, $requestHash, $started);
        }

        try {
            $response = Http::withBasicAuth((string) $this->config['secret'], '')
                ->asForm()
                ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
                ->timeout(8)
                ->post('https://api.stripe.com/v1/payment_intents', [
                    'amount' => $request->amountMinor,
                    'currency' => strtolower($request->currency),
                    'confirm' => 'true',
                    'metadata[checkout_id]' => $request->checkoutId,
                    'metadata[charge_ref]' => $request->chargeRef(),
                    'receipt_email' => $request->customerEmail,
                    'payment_method_data[type]' => 'card',
                ]);
        } catch (ConnectionException $e) {
            $this->throwFromConnection($e, $requestHash, $started);
        }

        $latency = $this->elapsedMs($started);
        $body = $response->json() ?? ['raw' => $response->body()];
        $responseHash = PayloadHasher::hash($body);

        if ($response->serverError() || $response->status() === 429 || $response->failed() && ($body['error']['type'] ?? '') !== 'card_error') {
            throw new HardGatewayException(
                gateway: $this->name(),
                message: (string) ($body['error']['message'] ?? 'Stripe hard failure'),
                latencyMs: $latency,
                reason: $response->status() === 429 ? 'rate_limited' : 'hard_failure',
                requestHash: $requestHash,
                responseHash: $responseHash,
                errorCode: (string) ($body['error']['code'] ?? $response->status()),
            );
        }

        $softDecline = ($body['error']['type'] ?? '') === 'card_error' || ($body['status'] ?? '') === 'requires_payment_method';

        return new ChargeResult(
            success: ! $softDecline && $response->successful(),
            gateway: $this->name(),
            chargeId: $body['id'] ?? null,
            latencyMs: $latency,
            requestHash: $requestHash,
            responseHash: $responseHash,
            outcome: $softDecline ? 'soft_decline' : 'success',
            errorCode: $body['error']['code'] ?? null,
            message: $body['error']['message'] ?? null,
        );
    }

    public function fetchCharge(string $chargeRef): ?ChargeResult
    {
        $started = hrtime(true);
        $requestHash = PayloadHasher::hash(['gateway' => $this->name(), 'op' => 'fetch', 'charge_ref' => $chargeRef]);

        if (($this->config['mode'] ?? 'simulate') === 'simulate') {
            return $this->simulateFetch($chargeRef, $requestHash, $started);
        }

        try {
            $response = Http::withBasicAuth((string) $this->config['secret'], '')
                ->timeout(3)
                ->get('https://api.stripe.com/v1/payment_intents/search', [
                    'query' => sprintf('metadata["charge_ref"]:"%s" OR metadata["checkout_id"]:"%s"', $chargeRef, $this->checkoutIdFromChargeRef($chargeRef)),
                    'limit' => 1,
                ]);
        } catch (ConnectionException $e) {
            throw $this->reconcileTimeout($e, $requestHash, $started);
        }

        if ($response->status() === 400) {
            return $this->fetchChargeByList($chargeRef, $requestHash, $started);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->serverError() || $response->status() === 429) {
            throw $this->reconcileInconclusive($requestHash, $started, (string) $response->status());
        }

        $data = $response->json('data') ?? [];

        if (! is_array($data) || $data === []) {
            return null;
        }

        return $this->resultFromIntent($data[0], $requestHash, $started);
    }

    public function refund(string $chargeId, int $amountMinor): RefundResult
    {
        $started = hrtime(true);

        if (($this->config['mode'] ?? 'simulate') === 'simulate') {
            return new RefundResult(true, $this->name(), 're_sim_'.Str::uuid(), $this->elapsedMs($started));
        }

        $response = Http::withBasicAuth((string) $this->config['secret'], '')
            ->asForm()
            ->timeout(8)
            ->post('https://api.stripe.com/v1/refunds', [
                'payment_intent' => $chargeId,
                'amount' => $amountMinor,
            ]);

        return new RefundResult(
            $response->successful(),
            $this->name(),
            $response->json('id'),
            $this->elapsedMs($started),
            $response->json('error.message'),
        );
    }

    public function healthProbe(): ProbeResult
    {
        $started = hrtime(true);

        if (($this->config['mode'] ?? 'simulate') === 'simulate') {
            if ($this->config['simulate_failure'] ?? false) {
                return new ProbeResult(false, $this->name(), $this->elapsedMs($started), 'simulated outage');
            }

            usleep(((int) ($this->config['simulate_latency_ms'] ?? 10)) * 1000);

            return new ProbeResult(true, $this->name(), $this->elapsedMs($started));
        }

        try {
            $response = Http::withBasicAuth((string) $this->config['secret'], '')
                ->timeout(4)
                ->get('https://api.stripe.com/v1/balance');

            return new ProbeResult($response->successful(), $this->name(), $this->elapsedMs($started), $response->successful() ? null : 'probe_failed');
        } catch (ConnectionException $e) {
            return new ProbeResult(false, $this->name(), $this->elapsedMs($started), $e->getMessage());
        }
    }

    /**
     * Fallback when PaymentIntents Search is unavailable. Only the 20 most recent
     * intents are scanned — enough for demo traffic; production must use search by
     * metadata[charge_ref], or the target intent can scroll off this page.
     */
    private function fetchChargeByList(string $chargeRef, string $requestHash, int $started): ?ChargeResult
    {
        try {
            $response = Http::withBasicAuth((string) $this->config['secret'], '')
                ->timeout(3)
                ->get('https://api.stripe.com/v1/payment_intents', ['limit' => 20]);
        } catch (ConnectionException $e) {
            throw $this->reconcileTimeout($e, $requestHash, $started);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->serverError() || $response->status() === 429) {
            throw $this->reconcileInconclusive($requestHash, $started, (string) $response->status());
        }

        $checkoutId = $this->checkoutIdFromChargeRef($chargeRef);

        foreach ($response->json('data') ?? [] as $intent) {
            if (! is_array($intent)) {
                continue;
            }

            $metadata = $intent['metadata'] ?? [];
            if (($metadata['charge_ref'] ?? null) === $chargeRef || ($metadata['checkout_id'] ?? null) === $checkoutId) {
                return $this->resultFromIntent($intent, $requestHash, $started);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function resultFromIntent(array $intent, string $requestHash, int $started): ChargeResult
    {
        $status = (string) ($intent['status'] ?? '');

        if ($status === 'processing') {
            throw $this->reconcileInconclusive($requestHash, $started, 'processing');
        }

        $success = in_array($status, ['succeeded', 'requires_capture'], true);
        $bodyHash = PayloadHasher::hash($intent);

        return new ChargeResult(
            success: $success,
            gateway: $this->name(),
            chargeId: $intent['id'] ?? null,
            latencyMs: $this->elapsedMs($started),
            requestHash: $requestHash,
            responseHash: $bodyHash,
            outcome: $success ? 'success' : 'soft_decline',
        );
    }

    private function simulate(ChargeRequest $request, string $requestHash, int $started): ChargeResult
    {
        usleep(((int) ($this->config['simulate_latency_ms'] ?? 25)) * 1000);

        if ($this->config['simulate_timeout'] ?? false) {
            throw new AmbiguousGatewayException(
                gateway: $this->name(),
                message: 'Simulated Stripe timeout',
                latencyMs: $this->elapsedMs($started),
                reason: 'timeout',
                requestHash: $requestHash,
                responseHash: PayloadHasher::hash(['error' => 'timeout']),
                errorCode: 'connection_error',
            );
        }

        if ($this->config['simulate_failure'] ?? false) {
            throw new HardGatewayException(
                gateway: $this->name(),
                message: 'Simulated Stripe outage',
                latencyMs: $this->elapsedMs($started),
                reason: 'hard_failure',
                requestHash: $requestHash,
                responseHash: PayloadHasher::hash(['error' => 'simulated_outage']),
                errorCode: 'simulated_outage',
            );
        }

        $chargeId = 'pi_sim_'.str_replace('-', '', (string) Str::uuid());

        return new ChargeResult(
            success: true,
            gateway: $this->name(),
            chargeId: $chargeId,
            latencyMs: $this->elapsedMs($started),
            requestHash: $requestHash,
            responseHash: PayloadHasher::hash(['id' => $chargeId, 'status' => 'succeeded']),
            outcome: 'success',
        );
    }

    private function simulateFetch(string $chargeRef, string $requestHash, int $started): ?ChargeResult
    {
        usleep(((int) ($this->config['simulate_latency_ms'] ?? 10)) * 1000);

        if ($this->config['simulate_reconcile_timeout'] ?? false) {
            throw new AmbiguousGatewayException(
                gateway: $this->name(),
                message: 'Simulated Stripe reconciliation timeout',
                latencyMs: $this->elapsedMs($started),
                reason: 'reconcile_timeout',
                requestHash: $requestHash,
                responseHash: PayloadHasher::hash(['error' => 'reconcile_timeout']),
                errorCode: 'connection_error',
            );
        }

        if (! ($this->config['simulate_charge_succeeded'] ?? false)) {
            return null;
        }

        $chargeId = 'pi_sim_'.substr(hash('sha256', $chargeRef), 0, 24);

        return new ChargeResult(
            success: true,
            gateway: $this->name(),
            chargeId: $chargeId,
            latencyMs: $this->elapsedMs($started),
            requestHash: $requestHash,
            responseHash: PayloadHasher::hash(['id' => $chargeId, 'status' => 'succeeded', 'charge_ref' => $chargeRef]),
            outcome: 'success',
        );
    }

    private function throwFromConnection(ConnectionException $e, string $requestHash, int $started): never
    {
        $reason = GatewayFailureClassifier::reason($e);
        $responseHash = PayloadHasher::hash(['error' => $e->getMessage()]);

        if (GatewayFailureClassifier::isAmbiguous($e)) {
            throw new AmbiguousGatewayException(
                gateway: $this->name(),
                message: $e->getMessage(),
                latencyMs: $this->elapsedMs($started),
                reason: $reason,
                requestHash: $requestHash,
                responseHash: $responseHash,
                errorCode: 'connection_error',
            );
        }

        throw new HardGatewayException(
            gateway: $this->name(),
            message: $e->getMessage(),
            latencyMs: $this->elapsedMs($started),
            reason: $reason,
            requestHash: $requestHash,
            responseHash: $responseHash,
            errorCode: 'connection_error',
        );
    }

    private function reconcileTimeout(ConnectionException $e, string $requestHash, int $started): AmbiguousGatewayException
    {
        return new AmbiguousGatewayException(
            gateway: $this->name(),
            message: 'Stripe reconciliation timed out: '.$e->getMessage(),
            latencyMs: $this->elapsedMs($started),
            reason: 'reconcile_timeout',
            requestHash: $requestHash,
            responseHash: PayloadHasher::hash(['error' => $e->getMessage()]),
            errorCode: 'connection_error',
        );
    }

    private function reconcileInconclusive(string $requestHash, int $started, string $code): AmbiguousGatewayException
    {
        return new AmbiguousGatewayException(
            gateway: $this->name(),
            message: 'Stripe reconciliation inconclusive',
            latencyMs: $this->elapsedMs($started),
            reason: 'reconcile_inconclusive',
            requestHash: $requestHash,
            responseHash: PayloadHasher::hash(['error' => $code]),
            errorCode: $code,
        );
    }

    private function checkoutIdFromChargeRef(string $chargeRef): string
    {
        return str_starts_with($chargeRef, 'ch_') ? substr($chargeRef, 3) : $chargeRef;
    }

    private function elapsedMs(int $started): int
    {
        return max(1, (int) ((hrtime(true) - $started) / 1_000_000));
    }
}
