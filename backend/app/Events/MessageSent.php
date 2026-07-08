<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Message $message)
    {
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;

        return [
            new Channel('conversation.'.$conversation->uuid),
            new PrivateChannel('inbox.'.$conversation->inbox_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->message->conversation->uuid,
            'message' => MessageResource::make($this->message->loadMissing('agent'))->resolve(),
        ];
    }
}
