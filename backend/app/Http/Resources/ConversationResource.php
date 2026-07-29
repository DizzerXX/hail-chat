<?php

namespace App\Http\Resources;

use App\Services\CmsUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'language' => $this->language,
            'visitor' => [
                'name' => $this->visitor_name,
                'email' => $this->visitor_email,
                'phone' => $this->visitor_phone,
            ],
            'inbox' => $this->whenLoaded('inbox', fn () => [
                'id' => $this->inbox->id,
                'slug' => $this->inbox->slug,
                'name' => $this->inbox->name,
                'channel_type' => $this->inbox->channel_type,
                // Agent-facing inbox config; never exposed on the public widget API.
                // The CMS URL is a template resolved against this conversation's
                // details + field answers (case_id, name, category, ...).
                ...($request->user() !== null
                    ? ['cms_url' => app(CmsUrlResolver::class)->resolve(
                        $this->inbox->channel_config['cms_url'] ?? null,
                        $this->resource,
                    )]
                    : []),
            ]),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn () => $this->assignedAgent === null ? null : [
                'id' => $this->assignedAgent->id,
                'display_name' => $this->assignedAgent->displayName(),
            ]),
            'team' => $this->whenLoaded('team', fn () => $this->team === null ? null : [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ]),
            'meta' => $this->meta,
            'last_activity_at' => $this->last_activity_at,
            'accepted_at' => $this->accepted_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
