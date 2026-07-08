<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Conversation $conversation)
    {
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('conversation.'.$this->conversation->uuid),
            new PrivateChannel('inbox.'.$this->conversation->inbox_id),
        ];

        if ($this->conversation->assigned_agent_id !== null) {
            $channels[] = new PrivateChannel('agents.'.$this->conversation->assigned_agent_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation' => ConversationResource::make(
                $this->conversation->loadMissing(['inbox', 'assignedAgent', 'team'])
            )->resolve(),
        ];
    }
}
