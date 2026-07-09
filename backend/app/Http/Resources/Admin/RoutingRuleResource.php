<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'name' => $this->name,
            'priority' => $this->priority,
            'conditions' => $this->conditions ?? [],
            'team' => $this->whenLoaded('team', fn () => $this->team === null ? null : [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ]),
            'agent' => $this->whenLoaded('agent', fn () => $this->agent === null ? null : [
                'id' => $this->agent->id,
                'display_name' => $this->agent->displayName(),
            ]),
            'is_active' => $this->is_active,
        ];
    }
}
