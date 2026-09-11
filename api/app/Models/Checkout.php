<?php

namespace App\Models;

use App\Enums\CheckoutState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkout extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'store_id',
        'offer_id',
        'state',
        'offer_snapshot',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
        'discount_code',
        'customer_email',
        'idempotency_key',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => CheckoutState::class,
            'offer_snapshot' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(CheckoutLineItem::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(CheckoutTransition::class);
    }

    public function gatewayEvents(): HasMany
    {
        return $this->hasMany(GatewayEvent::class);
    }
}
