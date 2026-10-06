<?php

namespace App\Services\Translation;

use App\Contracts\TranslationDriver;
use App\Contracts\TranslatorInterface;
use App\Models\TranslationCache;
use Illuminate\Support\Facades\Cache;

/**
 * Two-level text-keyed cache in front of the real translator, so identical text
 * never re-calls the model regardless of which conversation it came from:
 *
 *   1. fast tier  — the default cache store (Redis once configured), TTL'd
 *   2. durable    — a queryable translation_cache table, permanent
 *
 * Both are keyed by the hash of source+target+text. Only a miss in both reaches
 * the configured driver (http or ollama).
 */
class CachingTranslator implements TranslatorInterface
{
    public function __construct(private TranslationDriver $inner)
    {
    }

    public function translate(string $text, string $source, string $target): string
    {
        // Same language in and out is a no-op — skip both cache and network.
        if ($source === $target) {
            return $text;
        }

        $hash = hash('sha256', $source.':'.$target.':'.$text);
        $ttl = (int) config('translation.cache_ttl', 86400);

        return Cache::remember("translation:{$hash}", $ttl, function () use ($hash, $text, $source, $target) {
            $cached = TranslationCache::query()->where('hash', $hash)->value('translation');

            if (is_string($cached)) {
                return $cached;
            }

            $translation = $this->inner->translate($text, $source, $target);

            TranslationCache::query()->create([
                'hash' => $hash,
                'source' => $source,
                'target' => $target,
                'text' => $text,
                'translation' => $translation,
            ]);

            return $translation;
        });
    }
}
