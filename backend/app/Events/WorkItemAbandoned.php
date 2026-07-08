<?php

namespace App\Events;

use App\Models\Conversation;
use App\Services\WorkItems\Mitel\WorkItemSnapshot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The work item left the MiCC queue without an agent (Abandoned,
 * ExitedQueue, or an inbox routing failure).
 */
class WorkItemAbandoned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public WorkItemSnapshot $snapshot,
    ) {
    }
}
