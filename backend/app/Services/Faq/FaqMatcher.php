<?php

namespace App\Services\Faq;

use App\Enums\WebhookEvent;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\FaqQuery;
use App\Services\Webhooks\WebhookDispatcher;

class FaqMatcher
{
    public function __construct(private WebhookDispatcher $webhooks)
    {
    }

    /**
     * Keyword-match a visitor query against the FAQ base in the visitor's
     * language, falling back to the configured fallback language. Every
     * lookup is logged to faq_queries; misses fire the faq.missed webhook.
     */
    public function match(string $query, string $language, ?Conversation $conversation = null): ?Faq
    {
        $languages = array_values(array_unique([$language, config('tdechat.fallback_language')]));

        $best = null;

        foreach ($languages as $candidateLanguage) {
            $best = $this->bestFor($query, $candidateLanguage);

            if ($best !== null) {
                break;
            }
        }

        FaqQuery::create([
            'conversation_id' => $conversation?->id,
            'query_text' => $query,
            'language' => $language,
            'matched_faq_id' => $best?->id,
        ]);

        if ($best !== null) {
            $best->increment('usage_count');

            return $best;
        }

        $this->webhooks->dispatch(WebhookEvent::FaqMissed, [
            'query' => $query,
            'language' => $language,
            'conversation' => $conversation?->uuid,
            'inbox' => $conversation?->inbox?->slug,
        ], $conversation?->inbox);

        return null;
    }

    private function bestFor(string $query, string $language): ?Faq
    {
        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return null;
        }

        return Faq::query()
            ->active()
            ->language($language)
            ->get()
            ->map(fn (Faq $faq) => ['faq' => $faq, 'score' => $this->score($faq, $tokens)])
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sort(fn (array $a, array $b) => [$b['score'], $b['faq']->usage_count] <=> [$a['score'], $a['faq']->usage_count])
            ->first()['faq'] ?? null;
    }

    /**
     * Score = number of FAQ keywords fully present in the query. Multi-word
     * keywords require every word to appear.
     *
     * @param  list<string>  $tokens
     */
    private function score(Faq $faq, array $tokens): int
    {
        $score = 0;

        foreach ($faq->keywords ?? [] as $keyword) {
            $keywordTokens = $this->tokenize($keyword);

            if ($keywordTokens !== [] && array_intersect($keywordTokens, $tokens) === $keywordTokens) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
