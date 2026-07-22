<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A license synced from the central platform. One license activates one agent.
 */
class License extends Model
{
    protected $fillable = [
        'platform_license_id',
        'license_key',
        'status',
        'type',
        'customer_company',
        'issued_at',
        'expires_at',
        'activation_date',
        'max_users',
        'max_instances',
        'features',
        'metadata',
        'last_synced_at',
        'assigned_agent_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_license_id' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'activation_date' => 'datetime',
            'max_users' => 'integer',
            'max_instances' => 'integer',
            'features' => 'array',
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
            'assigned_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_agent_id');
    }

    /** Usable right now: active on the platform and not past its expiry. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_agent_id');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function maskedKey(): string
    {
        $key = (string) $this->license_key;

        return strlen($key) <= 8
            ? str_repeat('•', strlen($key))
            : substr($key, 0, 4).str_repeat('•', max(4, strlen($key) - 8)).substr($key, -4);
    }
}
