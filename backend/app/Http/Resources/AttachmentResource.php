<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'is_image' => $this->resource->isImage(),
            // Served inline; the uuid is the download capability.
            'url' => URL::to('/api/v1/public/attachments/'.$this->uuid),
        ];
    }
}
