<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row of system-wide settings (branding + data retention). Use
 * SystemSettings::current() to read it — materialised from config defaults on
 * first access, then process-cached like ChatSettings.
 */
class SystemSettings extends Model
{
    protected $guarded = [];

    /** Public branding surfaced to unauthenticated pages via app-config. */
    public const BRANDING = ['app_name', 'favicon', 'logo'];

    protected function casts(): array
    {
        return [
            'retention_enabled' => 'boolean',
            'retention_days' => 'integer',
            'last_purge_at' => 'datetime',
            'last_purge_count' => 'integer',
        ];
    }

    private static ?self $current = null;

    public static function current(): self
    {
        return self::$current ??= self::query()->firstOr(
            fn () => self::create(config('system.defaults')),
        );
    }

    /** Drop the process-level cache (after an update or in tests). */
    public static function forgetCurrent(): void
    {
        self::$current = null;
    }
}
