<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            // Served inline; the uuid is the download capability. Built from the
            // canonical APP_URL (not the request host) so it is identical whether
            // minted in an HTTP request or a queued broadcast — the widget can be
            // embedded on any domain and must always point back at this app.
            'url' => rtrim((string) config('app.url'), '/').'/api/v1/public/attachments/'.$this->uuid,
        ];
    }
}
