<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'agent' => $this->whenLoaded('agent', fn () => $this->agent === null ? null : [
                'id' => $this->agent->id,
                'display_name' => $this->agent->displayName(),
            ]),
            'body' => $this->body,
            'meta' => $this->meta,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments', fn () => $this->attachments, collect())),
            'created_at' => $this->created_at,
        ];
    }
}
