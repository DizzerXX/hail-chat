<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Webhook extends Model
{
    /** @use HasFactory<\Database\Factories\WebhookFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'inbox_id',
        'is_active',
    ];

    protected $hidden = [
        'secret',
    ];

    protected static function booted(): void
    {
        static::creating(function (Webhook $webhook) {
            $webhook->secret ??= Str::random(48);
        });
    }

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscribesTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events ?? [], true);
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
