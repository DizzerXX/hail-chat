<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\TrashedConversationResource;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Admin recycle bin for conversations (permission: conversations.delete).
 * Deleting soft-deletes into the bin; the bin auto-purges after
 * recycle_bin_days, and everything here can be restored until then.
 */
class ConversationController extends Controller
{
    public function __construct(private ConversationService $conversations)
    {
    }

    /** Soft-delete a conversation into the recycle bin. */
    public function destroy(Request $request, Conversation $conversation): Response
    {
        $conversation->deleted_by = $request->user()->id;
        $conversation->save();
        $conversation->delete();

        AuditLog::record($request->user(), 'conversation.deleted', $conversation, 'Moved a conversation to the recycle bin');

        return response()->noContent();
    }

    /** The recycle bin — soft-deleted conversations, newest first. */
    public function trashed(): AnonymousResourceCollection
    {
        $conversations = Conversation::onlyTrashed()
            ->with(['inbox', 'deletedBy'])
            ->latest('deleted_at')
            ->paginate(30);

        return TrashedConversationResource::collection($conversations);
    }

    /** Restore a conversation from the bin. */
    public function restore(Request $request, Conversation $conversation): Response
    {
        $conversation->restore();
        $conversation->forceFill(['deleted_by' => null])->save();

        AuditLog::record($request->user(), 'conversation.restored', $conversation, 'Restored a conversation from the recycle bin');

        return response()->noContent();
    }

    /** Permanently delete a conversation from the bin (irreversible). */
    public function forceDelete(Request $request, Conversation $conversation): Response
    {
        AuditLog::record($request->user(), 'conversation.purged', $conversation, 'Permanently deleted a conversation');

        $this->conversations->permanentlyDelete($conversation);

        return response()->noContent();
    }
}
