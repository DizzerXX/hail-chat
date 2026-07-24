<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * The public conversation channel (conversation.{uuid}) needs no entry here:
 * the unguessable conversation UUID is the visitor's capability.
 */

Broadcast::channel('inbox.{inboxId}', function (User $user, int $inboxId) {
    return $user->is_active
        && ($user->agent !== null || $user->hasPermission('conversations.view_all'));
});

/*
 * Internal notes for one conversation. Private (unlike the public
 * conversation.{uuid} channel) so notes never reach the visitor's widget; any
 * active agent or a supervisor who can view all conversations may listen.
 */
Broadcast::channel('conversation-notes.{uuid}', function (User $user, string $uuid) {
    return $user->is_active
        && ($user->agent !== null || $user->hasPermission('conversations.view_all'));
});

Broadcast::channel('agents.{agentId}', function (User $user, int $agentId) {
    return $user->is_active && $user->agent?->id === $agentId;
});
