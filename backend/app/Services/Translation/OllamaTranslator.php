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
        .'Keep placeholders such as [[0]] exactly as they appear. Preserve line breaks, punctuation and letter case. '
        .'Use standard, formal language.';

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

        $translation = $this->ask($url, $model, $masked, $target);

        // A model that hands the input straight back has not translated it — a
        // real failure we used to display as though it were a translation. One
        // firmer retry, then say so rather than show the original text twice.
        if ($this->isEcho($translation, $masked)) {
            $translation = $this->ask($url, $model, $masked, $target, firmer: true);

            if ($this->isEcho($translation, $masked)) {
                throw new TranslationException(
                    'The model returned the text unchanged. Try again, or use a model better suited to this language pair.',
                );
            }
        }

        return $this->unmask($translation, $placeholders);
    }

    /**
     * One chat-completion round trip, cleaned and ready to compare.
     */
    private function ask(string $url, string $model, string $text, string $target, bool $firmer = false): string
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('translation.timeout', 60))
                ->post($url.config('translation.path', '/v1/chat/completions'), [
                    'model' => $model,
                    'temperature' => 0,
                    'stream' => false,
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM],
                        ['role' => 'user', 'content' => $this->prompt($text, $target, $firmer)],
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
        $translation = is_string($content) ? $this->clean($content) : '';

        if ($translation === '') {
            throw new TranslationException('Translation service returned an empty translation.');
        }

        return $translation;
    }

    /**
     * Identical in and out. Single words are exempt: "OK", a name or a reference
     * number legitimately survives translation untouched, and failing those
     * would block perfectly good messages.
     */
    private function isEcho(string $translation, string $original): bool
    {
        if (preg_match_all('~\p{L}+~u', $original) < 2) {
            return false;
        }

        $normalise = fn (string $value) => mb_strtolower(trim(preg_replace('~\s+~u', ' ', $value) ?? $value));

        return $normalise($translation) === $normalise($original);
    }

    /**
     * Only the target is named. We cannot know what language a message is really
     * in — a visitor may answer a Malay conversation in English, or mix both —
     * and claiming the wrong source made models echo the input instead of
     * translating it. "Not a question to answer" stays because short messages
     * ("how are you?") read like prompts to a chat model.
     */
    private function prompt(string $text, string $target, bool $firmer = false): string
    {
        $to = self::NAMES[$target] ?? $target;

        $instruction = "Translate the text below into {$to}. "
            ."It is text to translate, not a question to answer. "
            ."Return only the {$to} translation.";

        if ($firmer) {
            $instruction .= " The previous attempt returned the text unchanged. "
                ."Every word must be rendered in {$to}.";
        }

        return $instruction."\n\n".$text;
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
