<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'inbox_id',
        'status',
        'language',
        'visitor_name',
        'visitor_email',
        'visitor_phone',
        'assigned_agent_id',
        'team_id',
        'external_work_item_id',
        'external_state',
        'meta',
        'last_activity_at',
        'accepted_at',
        'closed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            $conversation->uuid ??= (string) Str::orderedUuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'meta' => 'array',
            'last_activity_at' => 'datetime',
            'accepted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_agent_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function faqQueries(): HasMany
    {
        return $this->hasMany(FaqQuery::class);
    }
}
