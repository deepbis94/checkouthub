<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundWebhook extends Model
{
    protected $fillable = [
        'store_id',
        'event_type',
        'payload',
        'idempotency_key',
        'status',
        'attempts',
        'http_status',
        'last_attempt_at',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
