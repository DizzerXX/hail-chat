<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingRule extends Model
{
    /** @use HasFactory<\Database\Factories\RoutingRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'inbox_id',
        'name',
        'priority',
        'conditions',
        'team_id',
        'agent_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
