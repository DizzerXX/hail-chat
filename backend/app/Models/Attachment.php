<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a message. Its `uuid` is the public download capability
 * (see the /public/attachments/{uuid} route), keeping access consistent with
 * the conversation-uuid trust model.
 */
class Attachment extends Model
{
    protected $fillable = [
        'uuid',
        'message_id',
        'disk',
        'path',
        'name',
        'mime_type',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
