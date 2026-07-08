<?php

namespace App\Events;

use App\Models\Conversation;
use App\Services\WorkItems\Mitel\WorkItemSnapshot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A MiCC agent accepted the work item (ConversationState reached Acd).
 */
class WorkItemAccepted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public WorkItemSnapshot $snapshot,
    ) {
    }
}
