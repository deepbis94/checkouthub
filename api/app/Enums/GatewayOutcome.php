<?php

namespace App\Enums;

enum GatewayOutcome: string
{
    case Success = 'success';
    case HardFailure = 'hard_failure';
    case SoftDecline = 'soft_decline';
    case Failover = 'failover';
    case Skipped = 'skipped';
}
