<?php

namespace App\Models;

use App\Enums\AgentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agent extends Model
{
    /** @use HasFactory<\Database\Factories\AgentFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'display_name',
        'status',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'last_active_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    /** The license activating this agent, when licensing is configured. */
    public function license(): HasOne
    {
        return $this->hasOne(License::class, 'assigned_agent_id');
    }

    /**
     * Whether this agent holds a license that is usable right now (active and
     * unexpired). Used for strict per-seat enforcement — an agent whose seat
     * has lapsed is blocked from agent work and logged out.
     */
    public function hasUsableLicense(): bool
    {
        $license = $this->relationLoaded('license') ? $this->license : $this->license()->first();

        return $license !== null && $license->isUsable();
    }

    /**
     * Ids of the inboxes this agent may work: those held by one of their teams,
     * plus any inbox holding no team at all.
     *
     * An inbox needs a team to be created, so a team-less one means either a
     * row predating inbox access or the last holding team being deleted
     * (which cascades the pivot). Leaving those open to everyone keeps chats
     * answerable; failing closed would strand them silently.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function workableInboxIds(): Collection
    {
        return Inbox::query()
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('teams')
                ->orWhereHas('teams', fn (Builder $teams) => $teams->whereIn('teams.id', $this->teams()->select('teams.id'))))
            ->pluck('id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function displayName(): string
    {
        return $this->display_name ?? $this->user->name;
    }
}
