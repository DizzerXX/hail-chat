<?php

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Events\AgentNotified;
use App\Models\ChatSettings;
use App\Models\Conversation;
use App\Services\ConversationService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Once-a-minute sweep of active standalone conversations. Decides whose turn it
 * is from the last real (visitor/agent) message, then:
 *  - customer's turn: warn the visitor at warn1/warn2 and abandon at close;
 *  - agent's turn: nudge the assigned agent, repeating every interval.
 *
 * Progress is anchored in conversation.meta.idle so each silent stretch warns
 * once and a reply resets it. Mitel inboxes are skipped — MiCC owns those.
 */
class IdleSweepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(ConversationService $conversations): void
    {
        // This job runs inside the long-lived queue worker, whose process-static
        // ChatSettings cache would otherwise pin the values seen on the worker's
        // first sweep — an admin save (which forgets the cache in the *web*
        // process) would never reach us. Re-read fresh so edits take effect.
        ChatSettings::forgetCurrent();
        $settings = ChatSettings::current();

        if (! $settings->idle_enabled) {
            return;
        }

        $now = CarbonImmutable::now();

        // All active conversations — the per-channel branch decides what fires:
        // standalone gets the full flow (warn visitor + auto-close), Mitel gets
        // agent alerts only (MiCC owns the case, so no visitor messages and no
        // auto-close), but both nudge the agent.
        Conversation::query()
            ->where('status', ConversationStatus::Active)
            ->with(['inbox', 'assignedAgent'])
            ->each(fn (Conversation $conversation) => $this->sweep($conversation, $settings, $now, $conversations));
    }

    private function sweep(
        Conversation $conversation,
        ChatSettings $settings,
        CarbonImmutable $now,
        ConversationService $conversations,
    ): void {
        $isMitel = $conversation->inbox->channel_type === ChannelType::MitelOpenMedia;

        // Visitor closed the browser tab and didn't return within the grace
        // window — abandon it now, freeing the agent. Standalone only; on Mitel
        // the MiCC case owns the lifecycle. Overrides the turn-based flow below.
        if (! $isMitel && $conversation->visitor_left_at !== null) {
            $grace = (int) config('chat.visitor_left_grace_seconds');
            if ($conversation->visitor_left_at->diffInSeconds($now) >= $grace) {
                $conversations->abandonAfterLeaving($conversation);

                return;
            }
        }

        $lastVisitorAt = $this->lastMessageAt($conversation, SenderType::Visitor);
        $lastAgentAt = $this->lastMessageAt($conversation, SenderType::Agent);

        // No real turn yet (e.g. only a bot exchange) — nothing to chase.
        if ($lastVisitorAt === null && $lastAgentAt === null) {
            return;
        }

        $agentsTurn = $lastVisitorAt !== null
            && ($lastAgentAt === null || $lastVisitorAt->greaterThan($lastAgentAt));

        $turnAt = $agentsTurn ? $lastVisitorAt : $lastAgentAt;
        $anchor = $turnAt->toIso8601String();
        $idle = (int) $turnAt->diffInMinutes($now);

        // A change of anchor means someone spoke since — reset the idle block.
        $previous = $conversation->meta['idle'] ?? [];
        $state = ($previous['anchor'] ?? null) === $anchor
            ? $previous
            : ['anchor' => $anchor];

        $state = $agentsTurn
            ? $this->handleAgentIdle($conversation, $settings, $idle, $state)
            : $this->handleCustomerIdle($conversation, $settings, $idle, $state, $conversations, $isMitel);

        // Persist once — including a reset, so a reply clears prior warnings.
        if ($state !== $previous) {
            $this->persistIdleState($conversation, $state);
        }
    }

    private function handleCustomerIdle(
        Conversation $conversation,
        ChatSettings $settings,
        int $idle,
        array $state,
        ConversationService $conversations,
        bool $isMitel,
    ): array {
        // Auto-close is standalone-only; on Mitel the agent resolves in Ignite.
        if (! $isMitel && $idle >= $settings->customer_close_minutes) {
            $conversations->expireForInactivity(
                $conversation,
                $settings->text('closed', $conversation->language),
            );

            return $state;
        }

        if ($idle >= $settings->customer_warn2_minutes && ! ($state['warn2'] ?? false)) {
            $this->warnCustomer($conversation, $settings, 'warn2', $conversations, $isMitel);
            $state['warn2'] = true;
        } elseif ($idle >= $settings->customer_warn1_minutes && ! ($state['warn1'] ?? false)) {
            $this->warnCustomer($conversation, $settings, 'warn1', $conversations, $isMitel);
            $state['warn1'] = true;
        }

        return $state;
    }

    private function warnCustomer(
        Conversation $conversation,
        ChatSettings $settings,
        string $stage,
        ConversationService $conversations,
        bool $isMitel,
    ): void {
        $text = $settings->text($stage, $conversation->language);

        // The visitor-facing warning is standalone-only; Mitel just alerts the agent.
        if (! $isMitel) {
            $conversations->sendIdleWarning($conversation, $text);
        }

        $this->notifyAgent(
            $conversation,
            'customer_idle',
            'Customer is idle',
            trim(($conversation->visitor_name ?: 'The visitor').' has gone quiet — a follow-up may help.'),
            'warning',
            // Offer the same message as a one-click follow-up (used on Mitel, where
            // nothing was auto-sent).
            $isMitel ? $text : null,
        );
    }

    private function handleAgentIdle(Conversation $conversation, ChatSettings $settings, int $idle, array $state): array
    {
        if ($idle < $settings->agent_idle_minutes) {
            return $state;
        }

        // Nudge on first crossing, then once per interval until they reply.
        $nudges = $state['nudges'] ?? 0;
        $due = intdiv($idle, max(1, $settings->agent_idle_minutes));

        if ($due <= $nudges) {
            return $state;
        }

        $this->notifyAgent(
            $conversation,
            'agent_idle',
            'Waiting for your reply',
            trim(($conversation->visitor_name ?: 'A visitor').' is waiting for a response.'),
            'notification',
        );

        $state['nudges'] = $due;

        return $state;
    }

    private function notifyAgent(
        Conversation $conversation,
        string $type,
        string $title,
        string $body,
        string $sound,
        ?string $followUp = null,
    ): void {
        if ($conversation->assignedAgent === null) {
            return;
        }

        event(new AgentNotified($conversation->assignedAgent, $conversation, $type, $title, $body, $sound, $followUp));
    }

    private function persistIdleState(Conversation $conversation, array $state): void
    {
        $meta = $conversation->meta ?? [];
        $meta['idle'] = $state;
        // Write directly so we don't disturb last_activity_at (used elsewhere).
        $conversation->forceFill(['meta' => $meta])->saveQuietly();
    }

    private function lastMessageAt(Conversation $conversation, SenderType $sender): ?CarbonImmutable
    {
        $at = $conversation->messages()
            ->where('sender_type', $sender)
            ->max('created_at');

        return $at === null ? null : CarbonImmutable::parse($at);
    }
}
