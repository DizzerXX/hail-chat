<?php

namespace App\Http\Resources\Admin;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'agents' => $this->whenLoaded('agents', fn () => $this->agents->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'display_name' => $agent->displayName(),
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
