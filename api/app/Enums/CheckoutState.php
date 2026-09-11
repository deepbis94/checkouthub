<?php

namespace App\Enums;

enum CheckoutState: string
{
    case Pending = 'pending';
    case Authorizing = 'authorizing';
    case Complete = 'complete';
    case Failed = 'failed';
    case Expired = 'expired';
}
