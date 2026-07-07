<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Faq extends Model
{
    /** @use HasFactory<\Database\Factories\FaqFactory> */
    use HasFactory;

    protected $fillable = [
        'translation_group',
        'language',
        'question',
        'answer',
        'keywords',
        'is_active',
        'usage_count',
    ];

    protected static function booted(): void
    {
        static::creating(function (Faq $faq) {
            $faq->translation_group ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
        ];
    }

    public function scopeLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Sibling rows carrying this FAQ in other languages.
     */
    public function translations(): Builder
    {
        return static::query()
            ->where('translation_group', $this->translation_group)
            ->whereKeyNot($this->getKey());
    }

    public function faqQueries(): HasMany
    {
        return $this->hasMany(FaqQuery::class, 'matched_faq_id');
    }
}
