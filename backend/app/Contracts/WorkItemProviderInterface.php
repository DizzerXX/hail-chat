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
     * Send the conversation back to the queue, optionally retargeted.
     */
    public function rerouteWorkItem(Conversation $conversation, ?Team $team = null, ?Agent $agent = null): void;
}
