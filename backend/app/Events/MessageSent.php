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

        // Internal notes are agent-only: keep them OFF the public conversation
        // channel (which the visitor's widget subscribes to with just the uuid)
        // and broadcast solely on a private, authorized per-conversation notes
        // channel that only the agent chat view subscribes to.
        if ($this->message->sender_type === \App\Enums\SenderType::Note) {
            return [new PrivateChannel('conversation-notes.'.$conversation->uuid)];
        }

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
            'message' => MessageResource::make($this->message->loadMissing('agent', 'attachments'))->resolve(),
        ];
    }
}
