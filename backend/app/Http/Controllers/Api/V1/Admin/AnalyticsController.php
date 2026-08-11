<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FaqQuery;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->startOfDay()
            : $to->subDays(13)->startOfDay();

        abort_if($from->diffInDays($to) > 92, 422, 'The date range may span at most 92 days.');

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'conversations_per_day' => $this->conversationsPerDay($from, $to),
            'avg_handle_seconds' => $this->averageHandleSeconds($from, $to),
            'faq' => $this->faqStats($from, $to),
            'totals' => $this->totals($from, $to),
            'per_agent' => $this->perAgent($from, $to),
            'top_missed_queries' => $this->topMissedQueries($from, $to),
        ]);
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function conversationsPerDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = Conversation::whereBetween('created_at', [$from, $to])
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(CarbonPeriod::create($from, '1 day', $to))
            ->map(fn ($day) => [
                'date' => $day->toDateString(),
                'count' => (int) ($counts[$day->toDateString()] ?? 0),
            ])
            ->all();
    }

    private function averageHandleSeconds(CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $average = Conversation::where('status', ConversationStatus::Closed)
            ->whereNotNull('accepted_at')
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('avg(extract(epoch from (closed_at - accepted_at))) as avg_seconds')
            ->value('avg_seconds');

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * @return array{queries: int, hits: int, hit_rate: float|null}
     */
    private function faqStats(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $stats = FaqQuery::whereBetween('created_at', [$from, $to])
            ->selectRaw('count(*) as total, count(matched_faq_id) as matched')
            ->first();

        $total = (int) $stats->total;
        $matched = (int) $stats->matched;

        return [
            'queries' => $total,
            'hits' => $matched,
            'hit_rate' => $total === 0 ? null : round($matched / $total, 4),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byStatus = Conversation::whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $conversations = (int) $byStatus->sum();
        $abandoned = (int) ($byStatus[ConversationStatus::Abandoned->value] ?? 0);

        return [
            'conversations' => $conversations,
            'closed' => (int) ($byStatus[ConversationStatus::Closed->value] ?? 0),
            'abandoned' => $abandoned,
            'abandonment_rate' => $conversations === 0 ? null : round($abandoned / $conversations, 4),
            'active_now' => Conversation::where('status', ConversationStatus::Active)->count(),
            'awaiting_now' => Conversation::where('status', ConversationStatus::AwaitingAcceptance)->count(),
        ];
    }

    /**
     * Per-agent handled volume + average resolution time over the period.
     *
     * @return list<array{agent: string, handled: int, avg_resolution_seconds: float|null}>
     */
    private function perAgent(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Conversation::query()
            ->join('agents', 'conversations.assigned_agent_id', '=', 'agents.id')
            ->join('users', 'agents.user_id', '=', 'users.id')
            ->where('conversations.status', ConversationStatus::Closed)
            ->whereNotNull('conversations.accepted_at')
            ->whereBetween('conversations.closed_at', [$from, $to])
            ->groupBy('agents.id', 'agents.display_name', 'users.name')
            ->selectRaw('coalesce(agents.display_name, users.name) as agent, count(*) as handled, '
                .'avg(extract(epoch from (conversations.closed_at - conversations.accepted_at))) as avg_seconds')
            ->orderByDesc('handled')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'agent' => (string) $row->agent,
                'handled' => (int) $row->handled,
                'avg_resolution_seconds' => $row->avg_seconds === null ? null : round((float) $row->avg_seconds, 1),
            ])
            ->all();
    }

    /**
     * @return list<array{query: string, count: int}>
     */
    private function topMissedQueries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return FaqQuery::whereNull('matched_faq_id')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('query_text as query, count(*) as count')
            ->groupBy('query_text')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['query' => $row->query, 'count' => (int) $row->count])
            ->all();
    }
}
