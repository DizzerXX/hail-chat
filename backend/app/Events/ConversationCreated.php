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

class ConversationCreated implements ShouldBroadcast
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
        return [
            new Channel('conversation.'.$this->conversation->uuid),
            new PrivateChannel('inbox.'.$this->conversation->inbox_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.created';
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
