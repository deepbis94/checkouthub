<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOrder extends Model
{
    protected $fillable = [
        'store_id',
        'provider_order_id',
        'checkout_id',
        'email',
        'status',
        'total_minor',
        'currency',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'payload' => 'array',
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
}
