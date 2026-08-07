<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrashedConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'visitor_name' => $this->visitor_name,
            'inbox' => $this->whenLoaded('inbox', fn () => $this->inbox->name),
            'closed_at' => $this->closed_at,
            'deleted_at' => $this->deleted_at,
            // Who moved it here: an admin's name, or "Retention" for the system sweep.
            'deleted_by' => $this->deleted_by === null
                ? 'Retention'
                : ($this->whenLoaded('deletedBy', fn () => $this->deletedBy?->name ?? 'Unknown')),
        ];
    }
}
