<?php

namespace App\Services\WorkItems\Mitel;

use App\Enums\MitelConversationState;
use App\Models\Conversation;

final readonly class WorkItemSnapshot
{
    public function __construct(
        public int $conversationId,
        public MitelConversationState $state,
        public ?string $queueName,
        public ?int $positionInQueue,
        public ?string $agentId,
        public ?string $agentName,
        public array $raw,
        public bool $missing = false,
        public bool $terminal = false,
    ) {
    }

    /**
     * The work item 404'd off the realtime endpoint and even the historical
     * record has nothing — treated as abandoned.
     */
    public static function missing(Conversation $conversation): self
    {
        return new self(
            conversationId: $conversation->id,
            state: MitelConversationState::Unknown,
            queueName: null,
            positionInQueue: null,
            agentId: null,
            agentName: null,
            raw: [],
            missing: true,
            terminal: true,
        );
    }

    public static function fromResponse(Conversation $conversation, array $data, bool $terminal = false): self
    {
        // Field casing tolerant: swagger documents PascalCase, but the live
        // MCX queues endpoint emits camel/lowercase — cover both.
        $get = fn (string $key) => $data[$key] ?? $data[lcfirst($key)] ?? null;

        return new self(
            conversationId: $conversation->id,
            state: MitelConversationState::fromResponse($get('ConversationState')),
            queueName: $get('QueueName'),
            positionInQueue: $get('PositionInQueue') !== null ? (int) $get('PositionInQueue') : null,
            agentId: $get('AgentId') !== null ? (string) $get('AgentId') : null,
            agentName: $get('AgentName'),
            raw: $data,
            terminal: $terminal,
        );
    }
}
