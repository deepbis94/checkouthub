<?php

namespace App\Gateways;

use App\Contracts\GatewayAdapter;
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
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new HardGatewayException(
                gateway: $this->name(),
                message: $e->getMessage(),
                latencyMs: $this->elapsedMs($started),
                reason: 'timeout',
                requestHash: $requestHash,
                responseHash: PayloadHasher::hash(['error' => $e->getMessage()]),
                errorCode: 'connection_error',
            );
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

    private function simulate(ChargeRequest $request, string $requestHash, int $started): ChargeResult
    {
        usleep(((int) ($this->config['simulate_latency_ms'] ?? 30)) * 1000);

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

    private function elapsedMs(int $started): int
    {
        return max(1, (int) ((hrtime(true) - $started) / 1_000_000));
    }
}
