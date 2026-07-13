<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Enums\WebhookEvent;
use App\Events\ConversationCreated;
use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Http\Resources\ConversationResource;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\Team;
use App\Services\Faq\FaqMatcher;
use App\Services\WorkItems\WorkItemProviderResolver;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function __construct(
        private WorkItemProviderResolver $providers,
        private FaqMatcher $faqMatcher,
        private WebhookDispatcher $webhooks,
    ) {
    }

    /**
     * Start a conversation from the public widget: create it with the first
     * visitor message, then either engage the FAQ bot or queue it for agents.
     */
    public function start(Inbox $inbox, array $visitor, string $messageBody): Conversation
    {
        [$conversation, $firstMessage] = DB::transaction(function () use ($inbox, $visitor, $messageBody) {
            $conversation = $inbox->conversations()->create([
                'status' => ConversationStatus::Pending,
                'language' => $visitor['language'] ?? config('tdechat.default_language'),
                'visitor_name' => $visitor['visitor_name'] ?? null,
                'visitor_email' => $visitor['visitor_email'] ?? null,
                'visitor_phone' => $visitor['visitor_phone'] ?? null,
                'meta' => $visitor['meta'] ?? null,
                'last_activity_at' => now(),
            ]);

            $message = $conversation->messages()->create([
                'sender_type' => SenderType::Visitor,
                'body' => $messageBody,
            ]);

            return [$conversation, $message];
        });

        broadcast(new ConversationCreated($conversation));
        $this->maybeAutoTranslate($firstMessage);
        $this->webhooks->dispatch(
            WebhookEvent::ConversationStarted,
            ['conversation' => $this->payload($conversation)],
            $inbox,
        );

        if ($inbox->bot_enabled) {
            $conversation->status = ConversationStatus::Bot;
            $conversation->save();
            $this->botRespond($conversation, $messageBody);
        } else {
            $this->queueForAgents($conversation);
        }

        return $conversation->refresh()->load(['inbox', 'assignedAgent', 'team', 'messages.agent']);
    }

    /**
     * Append a visitor message; while the bot owns the conversation it also
     * gets a chance to answer.
     */
    public function visitorMessage(Conversation $conversation, string $body): Message
    {
        abort_unless($conversation->status->isOpen(), 409, 'This conversation is closed.');

        $message = $this->appendMessage($conversation, SenderType::Visitor, $body);

        if ($conversation->status === ConversationStatus::Bot) {
            $this->botRespond($conversation, $body);
        }

        return $message;
    }

    /**
     * Hand the conversation to human agents (visitor request or bot miss).
     */
    public function escalate(Conversation $conversation): Conversation
    {
        abort_unless(in_array($conversation->status, [
            ConversationStatus::Pending,
            ConversationStatus::Bot,
        ], true), 409, 'Only bot conversations can be escalated.');

        $this->queueForAgents($conversation);

        $this->webhooks->dispatch(
            WebhookEvent::ConversationEscalated,
            ['conversation' => $this->payload($conversation)],
            $conversation->inbox,
        );

        return $conversation;
    }

    public function accept(Conversation $conversation, Agent $agent): Conversation
    {
        abort_unless(
            $conversation->status === ConversationStatus::AwaitingAcceptance,
            409,
            'This conversation is not awaiting acceptance.',
        );

        $canOverride = $agent->user->hasPermission('conversations.assign');

        if ($conversation->assigned_agent_id !== null && $conversation->assigned_agent_id !== $agent->id && ! $canOverride) {
            abort(403, 'This conversation is offered to another agent.');
        }

        if (
            $conversation->assigned_agent_id === null
            && $conversation->team_id !== null
            && ! $canOverride
            && ! $agent->teams()->whereKey($conversation->team_id)->exists()
        ) {
            abort(403, 'This conversation is queued for another team.');
        }

        $conversation->forceFill([
            'assigned_agent_id' => $agent->id,
            'status' => ConversationStatus::Active,
            'accepted_at' => now(),
            'last_activity_at' => now(),
        ])->save();

        broadcast(new ConversationUpdated($conversation));

        return $conversation;
    }

    public function agentMessage(Conversation $conversation, Agent $agent, string $body): Message
    {
        abort_unless(
            $conversation->status === ConversationStatus::Active,
            409,
            'Messages can only be sent in active conversations.',
        );

        if ($conversation->assigned_agent_id !== $agent->id && ! $agent->user->hasPermission('conversations.assign')) {
            abort(403, 'This conversation is assigned to another agent.');
        }

        return $this->appendMessage($conversation, SenderType::Agent, $body, $agent);
    }

    public function close(Conversation $conversation, string $closedBy): Conversation
    {
        abort_unless($conversation->status->isOpen(), 409, 'This conversation is already closed.');

        $this->providers->resolve($conversation->inbox)->completeWorkItem($conversation);

        $this->appendMessage($conversation, SenderType::System, 'Conversation closed by '.$closedBy.'.');

        $this->webhooks->dispatch(
            WebhookEvent::ConversationClosed,
            ['conversation' => $this->payload($conversation), 'closed_by' => $closedBy],
            $conversation->inbox,
        );

        broadcast(new ConversationUpdated($conversation));

        return $conversation;
    }

    public function reroute(Conversation $conversation, ?Team $team, ?Agent $agent): Conversation
    {
        abort_unless(in_array($conversation->status, [
            ConversationStatus::AwaitingAcceptance,
            ConversationStatus::Active,
        ], true), 409, 'Only queued or active conversations can be rerouted.');

        $this->providers->resolve($conversation->inbox)->rerouteWorkItem($conversation, $team, $agent);

        broadcast(new ConversationUpdated($conversation));

        return $conversation;
    }

    private function queueForAgents(Conversation $conversation): void
    {
        $this->providers->resolve($conversation->inbox)->createWorkItem($conversation);

        broadcast(new ConversationUpdated($conversation));
    }

    private function botRespond(Conversation $conversation, string $query): void
    {
        $faq = $this->faqMatcher->match($query, $conversation->language, $conversation);

        if ($faq !== null) {
            $this->appendMessage($conversation, SenderType::Bot, $faq->answer, null, ['faq_id' => $faq->id]);

            return;
        }

        $config = $conversation->inbox->channel_config ?? [];

        if ($config['bot']['escalate_on_miss'] ?? true) {
            $this->escalate($conversation);
        }
    }

    private function appendMessage(
        Conversation $conversation,
        SenderType $senderType,
        string $body,
        ?Agent $agent = null,
        ?array $meta = null,
    ): Message {
        $message = $conversation->messages()->create([
            'sender_type' => $senderType,
            'agent_id' => $agent?->id,
            'body' => $body,
            'meta' => $meta,
        ]);

        $conversation->forceFill(['last_activity_at' => now()])->save();

        broadcast(new MessageSent($message));
        $this->maybeAutoTranslate($message);

        return $message;
    }

    private function maybeAutoTranslate(Message $message): void
    {
        if (
            config('translation.enabled')
            && config('translation.method') === 'auto'
            && in_array($message->sender_type, [SenderType::Visitor, SenderType::Agent], true)
        ) {
            \App\Jobs\TranslateMessageJob::dispatch($message);
        }
    }

    private function payload(Conversation $conversation): array
    {
        return ConversationResource::make(
            $conversation->loadMissing(['inbox', 'assignedAgent', 'team'])
        )->resolve();
    }
}
