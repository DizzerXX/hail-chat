<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseState extends Model
{
    protected $table = 'license_state';

    protected $fillable = [
        'instance_id',
        'status',
        'type',
        'expires_at',
        'max_users',
        'max_instances',
        'features',
        'metadata',
        'current_activations',
        'activated_at',
        'last_heartbeat_at',
        'last_valid_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'max_users' => 'integer',
            'max_instances' => 'integer',
            'features' => 'array',
            'metadata' => 'array',
            'current_activations' => 'integer',
            'activated_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_valid_at' => 'datetime',
        ];
    }
}
