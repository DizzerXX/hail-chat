<?php

namespace App\Models;

use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inbox extends Model
{
    /** @use HasFactory<\Database\Factories\InboxFactory> */
    use HasFactory;

    protected $table = 'inboxes';

    protected $fillable = [
        'name',
        'slug',
        'channel_type',
        'channel_config',
        'bot_enabled',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'channel_config' => 'array',
            'bot_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * The teams whose agents may work this inbox. Required: an inbox with no
     * team is worked by nobody (holders of conversations.view_all aside).
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }
}
