<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'is_active' => $this->is_active,
            'role' => $this->whenLoaded('role', fn () => $this->role->name),
            'permissions' => $this->whenLoaded(
                'role',
                fn () => $this->role->relationLoaded('permissions')
                    ? $this->role->permissions->pluck('name')
                    : $this->role->permissions()->pluck('name'),
            ),
            'agent' => $this->whenLoaded('agent', fn () => $this->agent === null ? null : AgentResource::make($this->agent)->resolve()),
        ];
    }
}
