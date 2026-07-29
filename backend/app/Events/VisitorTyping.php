<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ephemeral visitor typing indicator (widget -> agent), the mirror of
 * AgentTyping. Broadcast immediately on the public conversation channel that
 * the agent dashboard already listens to; never persisted.
 */
class VisitorTyping implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Conversation $conversation,
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
        return 'visitor.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->conversation->uuid,
            'typing' => $this->typing,
        ];
    }
}
