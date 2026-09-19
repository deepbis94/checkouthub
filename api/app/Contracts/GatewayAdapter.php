<?php

namespace App\Contracts;

use App\Gateways\ChargeRequest;
use App\Gateways\ChargeResult;
use App\Gateways\ProbeResult;
use App\Gateways\RefundResult;

interface GatewayAdapter
{
    public function name(): string;

    public function priority(): int;

    public function charge(ChargeRequest $request): ChargeResult;

    public function fetchCharge(string $chargeRef): ?ChargeResult;

    public function refund(string $chargeId, int $amountMinor): RefundResult;

    public function healthProbe(): ProbeResult;
}
