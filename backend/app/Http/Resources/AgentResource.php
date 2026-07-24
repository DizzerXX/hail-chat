<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName(),
            'status' => $this->status,
            'last_active_at' => $this->last_active_at,
            // Whether this agent's seat is usable right now. Disabled-open (true)
            // until the license platform is configured; false when the seat has
            // lapsed, which the dashboard treats as a forced logout.
            'licensed' => ! license()->configured() || $this->resource->hasUsableLicense(),
        ];
    }
}
