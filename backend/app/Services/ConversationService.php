<?php

namespace App\Services;

use App\Enums\ChannelType;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * A visitor attachment (with an optional caption). Attachments are for
     * human conversations, so the bot never responds to them.
     *
     * @param  list<UploadedFile>  $files
     */
    public function visitorAttachment(Conversation $conversation, ?string $body, array $files): Message
    {
        abort_unless($conversation->status->isOpen(), 409, 'This conversation is closed.');

        return $this->appendMessage($conversation, SenderType::Visitor, $body ?? '', null, null, $files);
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

        $this->assertAgentFree($conversation, $agent);

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

    /**
     * Attach an agent to a conversation that is open but unowned.
     *
     * This exists for the embedded chat window MiCC opens once an agent accepts
     * the work item in Ignite: by then the poller has usually already moved the
     * conversation to active (MiCC ACD'd it) while leaving assigned_agent_id
     * null, because MiCC never tells us which of its agents took the item. In
     * that state accept() would 409 and the agent could not even reply, so the
     * embed claims ownership instead. Idempotent when the caller already owns it.
     */
    public function claim(Conversation $conversation, Agent $agent): Conversation
    {
        if ($conversation->assigned_agent_id === $agent->id && $conversation->status === ConversationStatus::Active) {
            return $conversation;
        }

        if ($conversation->status === ConversationStatus::AwaitingAcceptance) {
            return $this->accept($conversation, $agent);
        }

        abort_unless(
            $conversation->status === ConversationStatus::Active,
            409,
            'This conversation is no longer open.',
        );

        abort_unless(
            $conversation->assigned_agent_id === null
                || $agent->user->hasPermission('conversations.assign'),
            409,
            'This conversation is assigned to another agent.',
        );

        $this->assertAgentFree($conversation, $agent);

        $conversation->forceFill([
            'assigned_agent_id' => $agent->id,
            'accepted_at' => $conversation->accepted_at ?? now(),
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

    /**
     * An agent attachment (with an optional caption), mirroring the guards on
     * agentMessage().
     *
     * @param  list<UploadedFile>  $files
     */
    public function agentAttachment(Conversation $conversation, Agent $agent, ?string $body, array $files): Message
    {
        abort_unless(
            $conversation->status === ConversationStatus::Active,
            409,
            'Messages can only be sent in active conversations.',
        );

        if ($conversation->assigned_agent_id !== $agent->id && ! $agent->user->hasPermission('conversations.assign')) {
            abort(403, 'This conversation is assigned to another agent.');
        }

        return $this->appendMessage($conversation, SenderType::Agent, $body ?? '', $agent, null, $files);
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

    /**
     * Auto-send a visitor-visible idle warning (as a bot message). Used by the
     * idle sweep; the text is already localised to the conversation language.
     */
    public function sendIdleWarning(Conversation $conversation, string $text): Message
    {
        return $this->appendMessage($conversation, SenderType::Bot, $text, null, ['idle_warning' => true]);
    }

    /**
     * Close a conversation the visitor abandoned. Records it as `abandoned`
     * (distinct from an agent-resolved close) after showing the visitor a final
     * message, and fires the `conversation.closed` webhook with reason `idle`.
     */
    public function expireForInactivity(Conversation $conversation, string $closingText): Conversation
    {
        if (! $conversation->status->isOpen()) {
            return $conversation;
        }

        $this->appendMessage($conversation, SenderType::Bot, $closingText, null, ['idle_closed' => true]);

        $conversation->forceFill([
            'status' => ConversationStatus::Abandoned,
            'closed_at' => now(),
        ])->save();

        $this->webhooks->dispatch(
            WebhookEvent::ConversationClosed,
            ['conversation' => $this->payload($conversation), 'closed_by' => 'system', 'reason' => 'idle'],
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

        // Targeting a busy agent would park the conversation where nobody can
        // accept it, so say so rather than creating a stuck chat.
        if ($agent !== null) {
            $this->assertAgentFree($conversation, $agent);
        }

        $this->providers->resolve($conversation->inbox)->rerouteWorkItem($conversation, $team, $agent);

        broadcast(new ConversationUpdated($conversation));

        return $conversation;
    }

    /**
     * An agent handles one conversation at a time.
     *
     * Mitel inboxes are exempt: MiCC does its own routing and has its own agent
     * capacity settings, so refusing here would strand a work item it has
     * already ACD'd to that agent.
     */
    private function assertAgentFree(Conversation $conversation, Agent $agent): void
    {
        if ($conversation->inbox->channel_type === ChannelType::MitelOpenMedia) {
            return;
        }

        $busy = $agent->conversations()
            ->where('status', ConversationStatus::Active)
            ->whereKeyNot($conversation->id)
            ->exists();

        abort_if($busy, 409, 'You are already handling a conversation. Close it before taking another.');
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

    /**
     * @param  list<UploadedFile>  $files
     */
    private function appendMessage(
        Conversation $conversation,
        SenderType $senderType,
        string $body,
        ?Agent $agent = null,
        ?array $meta = null,
        array $files = [],
    ): Message {
        $message = $conversation->messages()->create([
            'sender_type' => $senderType,
            'agent_id' => $agent?->id,
            'body' => $body,
            'meta' => $meta,
        ]);

        // Store any files and attach them BEFORE broadcasting, so the realtime
        // payload and the transcript both carry the attachments.
        foreach ($files as $file) {
            $disk = (string) config('attachments.disk');
            $path = $file->store('attachments/'.$conversation->uuid, $disk);

            $message->attachments()->create([
                'uuid' => (string) Str::uuid(),
                'disk' => $disk,
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        if ($files !== []) {
            $message->load('attachments');
        }

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
