<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutLineItem extends Model
{
    protected $fillable = [
        'checkout_id',
        'sku',
        'name',
        'quantity',
        'unit_amount_minor',
        'amount_minor',
        'type',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'amount_minor' => 'integer',
            'meta' => 'array',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }
}
