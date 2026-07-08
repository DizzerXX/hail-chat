<?php

namespace App\Events;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ephemeral typing indicator — broadcast immediately, never persisted.
 */
class AgentTyping implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public Agent $agent,
        public bool $typing = true,
    ) {
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('conversation.'.$this->conversation->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->conversation->uuid,
            'agent' => [
                'id' => $this->agent->id,
                'display_name' => $this->agent->displayName(),
            ],
            'typing' => $this->typing,
        ];
    }
}
