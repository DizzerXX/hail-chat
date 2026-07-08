<?php

namespace App\Services\Faq;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Collection;

class PublicFaqService
{
    /**
     * Active FAQs for the given language; unknown or missing languages fall
     * back to the configured default.
     *
     * @return Collection<int, Faq>
     */
    public function list(?string $language = null): Collection
    {
        return Faq::query()
            ->active()
            ->language($this->normalizeLanguage($language))
            ->orderBy('question')
            ->get();
    }

    private function normalizeLanguage(?string $language): string
    {
        if ($language !== null && in_array($language, config('tdechat.languages'), true)) {
            return $language;
        }

        return config('tdechat.default_language');
    }
}
