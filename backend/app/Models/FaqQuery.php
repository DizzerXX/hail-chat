<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqQuery extends Model
{
    /** @use HasFactory<\Database\Factories\FaqQueryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id',
        'query_text',
        'language',
        'matched_faq_id',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function matchedFaq(): BelongsTo
    {
        return $this->belongsTo(Faq::class, 'matched_faq_id');
    }

    public function isMiss(): bool
    {
        return $this->matched_faq_id === null;
    }
}
