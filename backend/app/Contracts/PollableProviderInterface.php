<?php

namespace App\Contracts;

use App\Services\WorkItems\Mitel\WorkItemSnapshot;
use Illuminate\Support\Collection;

/**
 * Providers whose work items live in an external system and must be polled
 * for state transitions. Kept separate from WorkItemProviderInterface on
 * purpose — standalone inboxes never poll.
 */
interface PollableProviderInterface
{
    /**
     * Fetch the current external state of every conversation still awaiting
     * acceptance on this provider's channel. Individual fetch failures are
     * logged and skipped, never fatal for the batch.
     *
     * @return Collection<int, WorkItemSnapshot>
     */
    public function pollPendingWorkItems(): Collection;
}
