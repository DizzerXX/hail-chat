<?php

namespace App\Services\Translation;

use App\Contracts\TranslatorInterface;
use App\Exceptions\TranslationException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP client for the external AI translation server.
 *
 * [Guessing] Assumed contract until the real AI server is wired up:
 *   POST {TRANSLATION_ENDPOINT}
 *   Authorization: Bearer {TRANSLATION_TOKEN}
 *   {"text": "...", "source": "ms", "target": "en"}
 *   → {"translation": "..."}
 *
 * Adapt THIS CLASS ONLY when connecting the real server — nothing else in
 * the translation feature depends on the wire format.
 */
class HttpTranslator implements TranslatorInterface
{
    public function translate(string $text, string $source, string $target): string
    {
        $endpoint = (string) config('translation.endpoint');

        if ($endpoint === '') {
            throw new TranslationException('The translation endpoint is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->when(config('translation.token') !== '', fn ($http) => $http->withToken(config('translation.token')))
                ->timeout(config('translation.timeout', 15))
                ->post($endpoint, [
                    'text' => $text,
                    'source' => $source,
                    'target' => $target,
                ]);
        } catch (Throwable $exception) {
            throw new TranslationException('Translation request failed: '.$exception->getMessage(), 0, $exception);
        }

        $translation = $response->json('translation');

        if (! $response->successful() || ! is_string($translation) || $translation === '') {
            throw new TranslationException(
                'Translation server answered HTTP '.$response->status().' without a translation.',
            );
        }

        return $translation;
    }
}
