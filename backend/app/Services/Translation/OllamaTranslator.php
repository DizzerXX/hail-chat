<?php

namespace App\Services\Translation;

use App\Contracts\TranslationDriver;
use App\Exceptions\TranslationException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to an Ollama server directly, with no translation microservice in
 * between:
 *
 *   POST {url}/v1/chat/completions   (OpenAI-compatible)
 *   {"model": "...", "temperature": 0, "messages": [system, user]}
 *
 * A general chat model will happily answer the text instead of translating it,
 * so three things keep it honest: a system message that forbids commentary, a
 * word-for-word user prompt, and a cleanup pass over the reply. Emails and URLs
 * are swapped for placeholders first, because models rewrite them otherwise.
 */
class OllamaTranslator implements TranslationDriver
{
    private const NAMES = ['ms' => 'Malay', 'en' => 'English'];

    private const SYSTEM = 'You are a literal translator. Translate the text you are given and return ONLY the translation. '
        .'Never answer, explain, comment, apologise or add words of your own. '
        .'Keep placeholders such as [[0]] exactly as they appear. Preserve line breaks, punctuation and letter case.';

    public function translate(string $text, string $source, string $target): string
    {
        $url = (string) config('translation.url');
        $model = (string) config('translation.model');

        if ($url === '') {
            throw new TranslationException('The translation service URL is not configured.');
        }

        if ($model === '') {
            throw new TranslationException('No translation model is configured (TRANSLATION_MODEL).');
        }

        [$masked, $placeholders] = $this->mask($text);

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('translation.timeout', 60))
                ->post($url.config('translation.path', '/v1/chat/completions'), [
                    'model' => $model,
                    'temperature' => 0,
                    'stream' => false,
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM],
                        ['role' => 'user', 'content' => $this->prompt($masked, $source, $target)],
                    ],
                ]);
        } catch (Throwable $exception) {
            throw new TranslationException('Translation request failed: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->json('error');

            throw new TranslationException('Translation failed: '.(is_string($error) ? $error : 'HTTP '.$response->status()));
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new TranslationException('Translation service returned an empty translation.');
        }

        $translation = $this->unmask($this->clean($content), $placeholders);

        if ($translation === '') {
            throw new TranslationException('Translation service returned an empty translation.');
        }

        return $translation;
    }

    /**
     * The instruction carries both language names so the model cannot guess the
     * direction, and repeats "do not answer" because short visitor messages read
     * like questions to a chat model.
     */
    private function prompt(string $text, string $source, string $target): string
    {
        $from = self::NAMES[$source] ?? $source;
        $to = self::NAMES[$target] ?? $target;

        return "Translate the following text from {$from} to {$to}. "
            ."It is text to translate, not a question to answer. "
            ."Return only the {$to} translation.\n\n".$text;
    }

    /**
     * Replace emails and URLs with [[n]] so the model leaves them alone.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function mask(string $text): array
    {
        $placeholders = [];

        $masked = preg_replace_callback(
            '~(https?://\S+|www\.\S+|[\w.+-]+@[\w-]+\.[\w.-]+)~i',
            function (array $match) use (&$placeholders): string {
                $token = '[['.count($placeholders).']]';
                $placeholders[$token] = $match[0];

                return $token;
            },
            $text,
        );

        return [$masked ?? $text, $placeholders];
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function unmask(string $text, array $placeholders): string
    {
        return $placeholders === [] ? $text : strtr($text, $placeholders);
    }

    /**
     * Strip the wrappers models add around a translation: reasoning blocks,
     * code fences, a "Translation:" label, and surrounding quotes. Internal
     * line breaks are kept — a multi-line message should stay multi-line.
     */
    private function clean(string $content): string
    {
        // Reasoning models (deepseek-r1 and friends) emit <think>…</think> first.
        $content = preg_replace('~<think>.*?</think>~is', '', $content) ?? $content;
        $content = trim($content);
        $content = preg_replace('~^```[a-z]*\s*|\s*```$~i', '', $content) ?? $content;
        $content = preg_replace('~^(translation|terjemahan)\s*[:\-]\s*~i', '', trim($content)) ?? $content;
        $content = trim($content);

        // Drop matching quotes the model wrapped the whole answer in.
        if (preg_match('~^"(.*)"$~s', $content, $m) || preg_match("~^'(.*)'$~s", $content, $m)) {
            $content = $m[1];
        }

        return trim($content);
    }
}
