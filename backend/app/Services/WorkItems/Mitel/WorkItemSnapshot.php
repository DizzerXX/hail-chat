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
    ) {
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
