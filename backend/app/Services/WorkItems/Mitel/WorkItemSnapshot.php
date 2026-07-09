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
    ) {
    }

    /**
     * The work item is absent from MiCC's active (InQueue/ACD) list —
     * it reached a Failed or Handled terminal state remotely.
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
        );
    }

    public static function fromResponse(Conversation $conversation, array $data): self
    {
        return new self(
            conversationId: $conversation->id,
            state: MitelConversationState::fromResponse($data['ConversationState'] ?? null),
            queueName: $data['QueueName'] ?? null,
            positionInQueue: isset($data['PositionInQueue']) ? (int) $data['PositionInQueue'] : null,
            agentId: isset($data['AgentId']) ? (string) $data['AgentId'] : null,
            agentName: $data['AgentName'] ?? null,
            raw: $data,
        );
    }
}
