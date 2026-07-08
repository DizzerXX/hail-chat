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

Broadcast::channel('agents.{agentId}', function (User $user, int $agentId) {
    return $user->is_active && $user->agent?->id === $agentId;
});
