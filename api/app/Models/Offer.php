<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'base_plan_code',
        'interval',
        'amount_minor',
        'currency',
        'trial_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function upsellRules(): HasMany
    {
        return $this->hasMany(UpsellRule::class);
    }
}
