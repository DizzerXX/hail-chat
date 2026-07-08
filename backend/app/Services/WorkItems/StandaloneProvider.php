<?php

namespace App\Services\WorkItems;

use App\Contracts\WorkItemProviderInterface;
use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Team;
use App\Services\RoutingService;

/**
 * Pure-database work-item management for standalone inboxes: no external
 * calls, conversation rows are the single source of truth.
 */
class StandaloneProvider implements WorkItemProviderInterface
{
    public function __construct(private RoutingService $routing)
    {
    }

    public function createWorkItem(Conversation $conversation): void
    {
        $firstVisitorMessage = $conversation->messages()
            ->where('sender_type', SenderType::Visitor)
            ->orderBy('id')
            ->first();

        $this->routing->route($conversation, $firstVisitorMessage?->body ?? '');

        $conversation->status = ConversationStatus::AwaitingAcceptance;
        $conversation->save();
    }

    public function completeWorkItem(Conversation $conversation): void
    {
        $conversation->status = ConversationStatus::Closed;
        $conversation->closed_at = now();
        $conversation->save();
    }

    public function rerouteWorkItem(Conversation $conversation, ?Team $team = null, ?Agent $agent = null): void
    {
        $conversation->team_id = $team?->id;
        $conversation->assigned_agent_id = $agent?->id;
        $conversation->accepted_at = null;
        $conversation->status = ConversationStatus::AwaitingAcceptance;
        $conversation->save();
    }
}
