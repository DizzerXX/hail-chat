<?php

namespace App\Services\Translation;

use App\Contracts\TranslationDriver;
use App\Exceptions\TranslationException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP client for the external SeaLLM/Ollama translation service.
 *
 * Wire contract (no auth; JSON in/out; en/ms only):
 *   POST {url}/translate
 *   {"text": "...", "source_language": "ms", "target_language": "en"}
 *
 * The service returns HTTP 200 even for business-logic failures, so success is
 * carried by the `success` flag, NOT the status code:
 *   200 {"success": true,  "translated_text": "..."}   → ok
 *   200 {"success": false, "error": "...timed out..."}  → failure
 *   400 {"error": "..."}                                → bad request
 *   500 {"success": false, "error": "..."}              → api fault
 *
 * Caching is handled by CachingTranslator, which wraps this class — this class
 * only speaks the wire format.
 */
class HttpTranslator implements TranslationDriver
{
    public function translate(string $text, string $source, string $target): string
    {
        $url = (string) config('translation.url');

        if ($url === '') {
            throw new TranslationException('The translation service URL is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('translation.timeout', 60))
                ->post($url.'/translate', [
                    'text' => $text,
                    'source_language' => $source,
                    'target_language' => $target,
                ]);
        } catch (Throwable $exception) {
            throw new TranslationException('Translation request failed: '.$exception->getMessage(), 0, $exception);
        }

        $data = $response->json();

        // Success is the `success` flag, never the HTTP status — a 200 can still
        // be a failure. 400/500 responses carry an `error` string too.
        if (! is_array($data) || ($data['success'] ?? false) !== true) {
            $error = is_array($data) ? ($data['error'] ?? null) : null;

            throw new TranslationException('Translation failed: '.($error ?? 'HTTP '.$response->status()));
        }

        $translation = $data['translated_text'] ?? null;

        if (! is_string($translation) || $translation === '') {
            throw new TranslationException('Translation service returned an empty translation.');
        }

        return $translation;
    }
}
