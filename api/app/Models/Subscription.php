<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'store_id',
        'checkout_id',
        'offer_id',
        'customer_email',
        'status',
        'gateway',
        'gateway_subscription_id',
        'amount_minor',
        'currency',
        'current_period_start',
        'current_period_end',
        'next_renewal_at',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_renewal_at' => 'datetime',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
