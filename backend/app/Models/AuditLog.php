<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account/system action performed by a user. Append-only; see
 * AuditLog::record().
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Append an audit entry. `actor` is the acting user (nullable for system
     * actions); `subject` is the affected model, if any. IP is captured from
     * the current request when available.
     */
    public static function record(
        ?User $actor,
        string $action,
        ?Model $subject = null,
        ?string $description = null,
    ): void {
        self::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'ip_address' => request()?->ip(),
        ]);
    }
}
