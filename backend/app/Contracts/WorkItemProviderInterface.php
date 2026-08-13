<?php

namespace App\Contracts;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Team;

/**
 * Work-item lifecycle behind every inbox channel. Standalone manages
 * Conversation rows directly; the Mitel Open Media provider (Phase 3)
 * mirrors these transitions onto MiCC work items.
 */
interface WorkItemProviderInterface
{
    /**
     * Hand the conversation to human agents (queueing/targeting included).
     */
    public function createWorkItem(Conversation $conversation): void;

    /**
     * Finish the work item and close the conversation.
     */
    public function completeWorkItem(Conversation $conversation): void;

    /**
     * Withdraw a work item that is still queued with no agent (the visitor left
     * before acceptance). Standalone has no external queue; the Mitel provider
     * may retract the MiCC item. Callers must only invoke this for an
     * unaccepted, still-queued conversation.
     */
    public function cancelWorkItem(Conversation $conversation): void;

    /**
     * Send the conversation back to the queue, optionally retargeted.
     */
    public function rerouteWorkItem(Conversation $conversation, ?Team $team = null, ?Agent $agent = null): void;
}
