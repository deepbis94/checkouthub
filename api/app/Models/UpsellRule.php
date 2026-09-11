<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpsellRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'name',
        'trigger_conditions',
        'eligibility_rules',
        'priority',
        'display_slot',
        'amount_minor',
        'is_stackable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'trigger_conditions' => 'array',
            'eligibility_rules' => 'array',
            'priority' => 'integer',
            'amount_minor' => 'integer',
            'is_stackable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
