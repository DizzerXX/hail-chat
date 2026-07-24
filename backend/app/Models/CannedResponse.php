<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-managed, org-wide quick reply agents can insert into the composer.
 */
class CannedResponse extends Model
{
    protected $fillable = [
        'title',
        'body',
        'language',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Active responses for a language: language-specific plus the any-language ones. */
    public function scopeUsableFor(Builder $query, ?string $language): Builder
    {
        return $query->where('is_active', true)
            ->when($language !== null, fn (Builder $q) => $q->where(function (Builder $q) use ($language) {
                $q->whereNull('language')->orWhere('language', $language);
            }));
    }
}
