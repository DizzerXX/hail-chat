<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role_id' => $this->role_id,
            'role' => $this->whenLoaded('role', fn () => $this->role->name),
            'is_active' => $this->is_active,
            'agent' => $this->whenLoaded('agent', fn () => $this->agent === null ? null : [
                'id' => $this->agent->id,
                'display_name' => $this->agent->display_name,
                'status' => $this->agent->status,
                'max_concurrent_chats' => $this->agent->max_concurrent_chats,
                'team_ids' => $this->agent->relationLoaded('teams')
                    ? $this->agent->teams->pluck('id')
                    : [],
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
