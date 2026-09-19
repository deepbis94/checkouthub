<?php

namespace App\Gateways;

use App\Contracts\GatewayAdapter;
use App\Exceptions\AmbiguousGatewayException;
use App\Exceptions\HardGatewayException;
use App\Support\PayloadHasher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class RazorpayAdapter implements GatewayAdapter
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function priority(): int
    {
        return (int) ($this->config['priority'] ?? 2);
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $started = hrtime(true);
        $requestHash = PayloadHasher::hash($request->toHashableArray() + ['gateway' => $this->name()]);

        if (($this->config['mode'] ?? 'simulate') === 'simulate') {
            return $this->simulate($request, $requestHash, $started);
        }

        try {
            $response = Http::withBasicAuth((string) $this->config['key'], (string) $this->config['secret'])
                ->withHeaders(['X-Razorpay-Idempotency' => $request->idempotencyKey])
                ->timeout(8)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $request->amountMinor,
                    'currency' => strtoupper($request->currency),
                    'receipt' => $request->checkoutId,
                    'notes' => [
                        'checkout_id' => $request->checkoutId,
                        'store_id' => (string) $request->storeId,
                        'charge_ref' => $request->chargeRef(),
                    ],
                ]);
        } catch (ConnectionException $e) {
            $this->throwFromConnection($e, $requestHash, $started);
        }

        $latency = $this->elapsedMs($started);
        $body = $response->json() ?? ['raw' => $response->body()];
        $responseHash = PayloadHasher::hash($body);

        if ($response->serverError() || $response->status() === 429 || $response->failed()) {
            $code = (string) ($body['error']['code'] ?? $response->status());
            $isSoft = in_array($code, ['BAD_REQUEST_ERROR', 'card_declined'], true) && $response->status() < 500;

            if (! $isSoft) {
                throw new HardGatewayException(
                    gateway: $this->name(),
                    message: (string) ($body['error']['description'] ?? 'Razorpay hard failure'),
                    latencyMs: $latency,
                    reason: $response->status() === 429 ? 'rate_limited' : 'hard_failure',
                    requestHash: $requestHash,
                    responseHash: $responseHash,
                    errorCode: $code,
                );
            }

            return new ChargeResult(
                success: false,
                gateway: $this->name(),
                chargeId: null,
                latencyMs: $latency,
                requestHash: $requestHash,
                responseHash: $responseHash,
                outcome: 'soft_decline',
                errorCode: $code,
                message: $body['error']['description'] ?? null,
            );
        }

        return new ChargeResult(
            success: true,
            gateway: $this->name(),
            chargeId: $body['id'] ?? null,
            latencyMs: $latency,
            requestHash: $requestHash,
            responseHash: $responseHash,
            outcome: 'success',
        );
    }

    public function fetchCharge(string $chargeRef): ?ChargeResult
    {
        $started = hrtime(true);
        $requestHash = PayloadHasher::hash(['gateway' => $this->name(), 'op' => 'fetch', 'charge_ref' => $chargeRef]);

        if (($this->config['mode'] ?? 'simulate') === 'simulate') {
            return $this->simulateFetch($chargeRef, $requestHash, $started);
        }

        $checkoutId = $this->checkoutIdFromChargeRef($chargeRef);

        try {
            $response = Http::withBasicAuth((string) $this->config['key'], (string) $this->config['secret'])
                ->timeout(3)
                ->get('https://api.razorpay.com/v1/orders', [
                    'receipt' => $checkoutId,
                    'count' => 10,
                ]);
        } catch (ConnectionException $e) {
            throw $this->reconcileTimeout($e, $requestHash, $started);
        }

        if ($response->status() === 404) {
            return $this->fetchChargeFromPayments($chargeRef, $checkoutId, $requestHash, $started);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw $this->reconcileInconclusive($requestHash, $started, (string) $response->status());
        }

        foreach ($response->json('items') ?? [] as $order) {
            if (! is_array($order)) {
                continue;
            }

            $notesRef = $order['notes']['charge_ref'] ?? null;
            $receipt = $order['receipt'] ?? null;

            if ($notesRef === $chargeRef || $receipt === $checkoutId) {
                return $this->resultFromOrder($order, $requestHash, $started);
            }
        }

        return $this->fetchChargeFromPayments($chargeRef, $checkoutId, $requestHash, $started);
    }

    public function refund(string $chargeId, int $amountMinor): RefundResult
    {
        $started = hrtime(true);

        if (($this->config['mode'] ?? 'simulate') === 'simulate') {
            return new RefundResult(true, $this->name(), 'rfnd_sim_'.Str::uuid(), $this->elapsedMs($started));
        }

        $response = Http::withBasicAuth((string) $this->config['key'], (string) $this->config['secret'])
            ->timeout(8)
            ->post("https://api.razorpay.com/v1/payments/{$chargeId}/refund", [
                'amount' => $amountMinor,
            ]);

        return new RefundResult(
            $response->successful(),
            $this->name(),
            $response->json('id'),
            $this->elapsedMs($started),
            $response->json('error.description'),
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
            $response = Http::withBasicAuth((string) $this->config['key'], (string) $this->config['secret'])
                ->timeout(4)
                ->get('https://api.razorpay.com/v1/payments', ['count' => 1]);

            return new ProbeResult($response->successful(), $this->name(), $this->elapsedMs($started), $response->successful() ? null : 'probe_failed');
        } catch (ConnectionException $e) {
            return new ProbeResult(false, $this->name(), $this->elapsedMs($started), $e->getMessage());
        }
    }

    private function fetchChargeFromPayments(string $chargeRef, string $checkoutId, string $requestHash, int $started): ?ChargeResult
    {
        try {
            $response = Http::withBasicAuth((string) $this->config['key'], (string) $this->config['secret'])
                ->timeout(3)
                ->get('https://api.razorpay.com/v1/payments', ['count' => 20]);
        } catch (ConnectionException $e) {
            throw $this->reconcileTimeout($e, $requestHash, $started);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->serverError() || $response->status() === 429) {
            throw $this->reconcileInconclusive($requestHash, $started, (string) $response->status());
        }

        foreach ($response->json('items') ?? [] as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $notes = $payment['notes'] ?? [];
            if (($notes['charge_ref'] ?? null) === $chargeRef || ($notes['checkout_id'] ?? null) === $checkoutId) {
                $success = in_array((string) ($payment['status'] ?? ''), ['authorized', 'captured', 'created'], true);
                $bodyHash = PayloadHasher::hash($payment);

                if ((string) ($payment['status'] ?? '') === 'created' && ! $success) {
                    $success = true;
                }

                return new ChargeResult(
                    success: $success,
                    gateway: $this->name(),
                    chargeId: $payment['id'] ?? null,
                    latencyMs: $this->elapsedMs($started),
                    requestHash: $requestHash,
                    responseHash: $bodyHash,
                    outcome: $success ? 'success' : 'soft_decline',
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function resultFromOrder(array $order, string $requestHash, int $started): ChargeResult
    {
        $status = (string) ($order['status'] ?? 'created');
        $success = in_array($status, ['created', 'attempted', 'paid'], true);

        return new ChargeResult(
            success: $success,
            gateway: $this->name(),
            chargeId: $order['id'] ?? null,
            latencyMs: $this->elapsedMs($started),
            requestHash: $requestHash,
            responseHash: PayloadHasher::hash($order),
            outcome: $success ? 'success' : 'soft_decline',
        );
    }

    private function simulate(ChargeRequest $request, string $requestHash, int $started): ChargeResult
    {
        usleep(((int) ($this->config['simulate_latency_ms'] ?? 30)) * 1000);

        if ($this->config['simulate_timeout'] ?? false) {
            throw new AmbiguousGatewayException(
                gateway: $this->name(),
                message: 'Simulated Razorpay timeout',
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
                message: 'Simulated Razorpay outage',
                latencyMs: $this->elapsedMs($started),
                reason: 'hard_failure',
                requestHash: $requestHash,
                responseHash: PayloadHasher::hash(['error' => 'simulated_outage']),
                errorCode: 'simulated_outage',
            );
        }

        $chargeId = 'order_sim_'.str_replace('-', '', (string) Str::uuid());

        return new ChargeResult(
            success: true,
            gateway: $this->name(),
            chargeId: $chargeId,
            latencyMs: $this->elapsedMs($started),
            requestHash: $requestHash,
            responseHash: PayloadHasher::hash(['id' => $chargeId, 'status' => 'created']),
            outcome: 'success',
        );
    }

    private function simulateFetch(string $chargeRef, string $requestHash, int $started): ?ChargeResult
    {
        usleep(((int) ($this->config['simulate_latency_ms'] ?? 10)) * 1000);

        if ($this->config['simulate_reconcile_timeout'] ?? false) {
            throw new AmbiguousGatewayException(
                gateway: $this->name(),
                message: 'Simulated Razorpay reconciliation timeout',
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

        $chargeId = 'order_sim_'.substr(hash('sha256', $chargeRef), 0, 24);

        return new ChargeResult(
            success: true,
            gateway: $this->name(),
            chargeId: $chargeId,
            latencyMs: $this->elapsedMs($started),
            requestHash: $requestHash,
            responseHash: PayloadHasher::hash(['id' => $chargeId, 'status' => 'created', 'charge_ref' => $chargeRef]),
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
            message: 'Razorpay reconciliation timed out: '.$e->getMessage(),
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
            message: 'Razorpay reconciliation inconclusive',
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
