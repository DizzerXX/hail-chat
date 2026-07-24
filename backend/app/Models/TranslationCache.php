<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable, text-keyed translation cache. One row per distinct
 * source+target+text, shared across all conversations.
 */
class TranslationCache extends Model
{
    protected $table = 'translation_cache';

    protected $fillable = ['hash', 'source', 'target', 'text', 'translation'];
}
