<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'platform',
        'platform_identifier',
        'api_key_prefix',
        'api_key_hash',
        'currency',
        'webhook_secret',
        'outbound_webhook_url',
        'settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(Checkout::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public static function hashApiKey(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
