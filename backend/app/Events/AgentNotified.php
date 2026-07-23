<?php

namespace App\Events;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An out-of-band nudge to a specific agent (idle prompts). Delivered on the
 * agent's private channel; the dashboard turns it into a badge, a sound and a
 * desktop notification. `sound` is 'warning' (fixed alert) or 'notification'
 * (the admin-selected file).
 */
class AgentNotified implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Agent $agent,
        public Conversation $conversation,
        public string $type,
        public string $title,
        public string $body,
        public string $sound,
    ) {
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('agents.'.$this->agent->id)];
    }

    public function broadcastAs(): string
    {
        return 'agent.notified';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'conversation_uuid' => $this->conversation->uuid,
            'title' => $this->title,
            'body' => $this->body,
            'sound' => $this->sound,
        ];
    }
}
