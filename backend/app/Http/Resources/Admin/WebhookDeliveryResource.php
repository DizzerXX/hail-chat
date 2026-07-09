<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'last_status_code' => $this->last_status_code,
            'last_error' => $this->last_error,
            'next_attempt_at' => $this->next_attempt_at,
            'completed_at' => $this->completed_at,
            'payload' => $this->payload,
            'created_at' => $this->created_at,
        ];
    }
}
