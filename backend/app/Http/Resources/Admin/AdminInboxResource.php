<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInboxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'channel_type' => $this->channel_type,
            'channel_config' => $this->channel_config ?? [],
            'bot_enabled' => $this->bot_enabled,
            'is_active' => $this->is_active,
            'conversations_count' => $this->whenCounted('conversations'),
            'created_at' => $this->created_at,
        ];
    }
}
