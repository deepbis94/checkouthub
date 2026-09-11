<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayEvent extends Model
{
    protected $fillable = [
        'checkout_id',
        'gateway',
        'attempt_no',
        'request_hash',
        'response_hash',
        'latency_ms',
        'outcome',
        'failover_reason',
        'charge_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'latency_ms' => 'integer',
            'meta' => 'array',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }
}
