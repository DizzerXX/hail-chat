<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqGroupRequest;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FaqGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $groups = Faq::query()
            ->when($validated['search'] ?? null, function ($query, string $search) {
                $matching = Faq::where('question', 'ilike', "%{$search}%")
                    ->orWhere('answer', 'ilike', "%{$search}%")
                    ->select('translation_group');

                $query->whereIn('translation_group', $matching);
            })
            ->orderByDesc('updated_at')
            ->limit(1000)
            ->get()
            ->groupBy('translation_group')
            ->map(fn (Collection $faqs, string $group) => $this->groupPayload($group, $faqs))
            ->values();

        return response()->json(['data' => $groups]);
    }

    public function store(FaqGroupRequest $request): JsonResponse
    {
        $group = (string) Str::uuid();

        $this->upsertTranslations($group, $request->validated('translations'));

        return response()->json(['data' => $this->freshGroup($group)], 201);
    }

    public function update(FaqGroupRequest $request, string $translationGroup): JsonResponse
    {
        abort_unless(
            Faq::where('translation_group', $translationGroup)->exists(),
            404,
        );

        // Transactional so the last-language guard rolls back any deletions.
        DB::transaction(function () use ($request, $translationGroup) {
            $this->upsertTranslations($translationGroup, $request->validated('translations'));

            abort_if(
                Faq::where('translation_group', $translationGroup)->doesntExist(),
                422,
                'A FAQ must keep at least one language version; delete the group instead.',
            );
        });

        return response()->json(['data' => $this->freshGroup($translationGroup)]);
    }

    public function destroy(string $translationGroup): Response
    {
        abort_unless(
            Faq::where('translation_group', $translationGroup)->exists(),
            404,
        );

        Faq::where('translation_group', $translationGroup)->delete();

        return response()->noContent();
    }

    /**
     * Upsert per language: an array upserts the row, an explicit null
     * removes that language version.
     */
    private function upsertTranslations(string $group, array $translations): void
    {
        foreach ($translations as $language => $payload) {
            if (! in_array($language, config('tdechat.languages'), true)) {
                continue;
            }

            if ($payload === null) {
                Faq::where('translation_group', $group)->where('language', $language)->delete();

                continue;
            }

            Faq::updateOrCreate(
                ['translation_group' => $group, 'language' => $language],
                [
                    'question' => $payload['question'],
                    'answer' => $payload['answer'],
                    'keywords' => $payload['keywords'] ?? [],
                    'is_active' => $payload['is_active'] ?? true,
                ],
            );
        }
    }

    private function freshGroup(string $group): array
    {
        return $this->groupPayload($group, Faq::where('translation_group', $group)->get());
    }

    /**
     * @param  Collection<int, Faq>  $faqs
     */
    private function groupPayload(string $group, Collection $faqs): array
    {
        return [
            'translation_group' => $group,
            'translations' => collect(config('tdechat.languages'))
                ->mapWithKeys(function (string $language) use ($faqs) {
                    $faq = $faqs->firstWhere('language', $language);

                    return [$language => $faq === null ? null : [
                        'id' => $faq->id,
                        'question' => $faq->question,
                        'answer' => $faq->answer,
                        'keywords' => $faq->keywords ?? [],
                        'is_active' => $faq->is_active,
                        'usage_count' => $faq->usage_count,
                    ]];
                }),
            'usage_count' => $faqs->sum('usage_count'),
            'updated_at' => $faqs->max('updated_at'),
        ];
    }
}
