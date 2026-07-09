<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminWebhookResource extends JsonResource
{
    /**
     * Admin-facing: includes the secret so integrators can configure the
     * receiving side. These endpoints sit behind webhooks.manage.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'secret' => $this->secret,
            'events' => $this->events,
            'inbox_id' => $this->inbox_id,
            'inbox' => $this->whenLoaded('inbox', fn () => $this->inbox === null ? null : [
                'id' => $this->inbox->id,
                'name' => $this->inbox->name,
                'slug' => $this->inbox->slug,
            ]),
            'is_active' => $this->is_active,
            'deliveries_count' => $this->whenCounted('deliveries'),
            'failed_deliveries_count' => $this->whenCounted('failed_deliveries_count'),
            'created_at' => $this->created_at,
        ];
    }
}
