<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\RoutingRule;

class RoutingService
{
    /**
     * Apply the first matching active rule (ascending priority) to target
     * the conversation at a team or a specific agent. No match leaves the
     * conversation in the general queue.
     */
    public function route(Conversation $conversation, string $messageBody): void
    {
        $rule = $conversation->inbox->routingRules()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->first(fn (RoutingRule $rule) => $this->matches($rule, $conversation, $messageBody));

        if ($rule === null) {
            return;
        }

        $conversation->team_id = $rule->team_id;
        $conversation->assigned_agent_id = $this->available($rule->agent_id, $conversation)
            ? $rule->agent_id
            : null;
    }

    /**
     * An agent handles one conversation at a time, so targeting one who is
     * already chatting would park this conversation where they cannot accept
     * it. A rule targets a team or an agent (never both), so skipping a busy
     * agent drops it into the general queue where anyone can pick it up.
     * Mitel inboxes are exempt — MiCC does its own routing.
     */
    private function available(?int $agentId, Conversation $conversation): bool
    {
        if ($agentId === null) {
            return false;
        }

        if ($conversation->inbox->channel_type === ChannelType::MitelOpenMedia) {
            return true;
        }

        return ! Conversation::query()
            ->where('assigned_agent_id', $agentId)
            ->where('status', ConversationStatus::Active)
            ->exists();
    }

    private function matches(RoutingRule $rule, Conversation $conversation, string $messageBody): bool
    {
        $conditions = $rule->conditions ?? [];

        if (isset($conditions['language']) && $conditions['language'] !== $conversation->language) {
            return false;
        }

        if (! empty($conditions['keywords'])) {
            $haystack = mb_strtolower($messageBody);

            $hit = collect($conditions['keywords'])
                ->contains(fn (string $keyword) => str_contains($haystack, mb_strtolower($keyword)));

            if (! $hit) {
                return false;
            }
        }

        return true;
    }
}
