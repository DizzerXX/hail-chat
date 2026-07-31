<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\SenderType;
use App\Events\VisitorTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\StartConversationRequest;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\TypingRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ConversationController extends Controller
{
    public function __construct(private ConversationService $conversations)
    {
    }

    public function store(StartConversationRequest $request, Inbox $inbox): JsonResponse
    {
        abort_unless($inbox->is_active, 404);

        $conversation = $this->conversations->start(
            $inbox,
            $request->validated(),
            $request->validated('message'),
        );

        return ConversationResource::make($conversation)->response()->setStatusCode(201);
    }

    public function show(Conversation $conversation): ConversationResource
    {
        // Internal notes are agent-only and must never reach the widget.
        $conversation->load(['inbox', 'assignedAgent', 'team'])
            ->load(['messages' => function ($query) {
                $query->where('sender_type', '!=', SenderType::Note)->with('agent', 'attachments');
            }]);

        return ConversationResource::make($conversation);
    }

    public function message(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->conversations->visitorMessage($conversation, $request->validated('body'));

        $conversation->refresh();

        $botReply = $conversation->messages()
            ->where('id', '>', $message->id)
            ->where('sender_type', SenderType::Bot)
            ->first();

        return response()->json([
            'message' => MessageResource::make($message)->resolve(),
            'bot_reply' => $botReply === null ? null : MessageResource::make($botReply)->resolve(),
            'status' => $conversation->status,
        ], 201);
    }

    public function attachment(StoreAttachmentRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->conversations->visitorAttachment(
            $conversation,
            $request->validated('body'),
            [$request->file('file')],
        );

        return response()->json([
            'message' => MessageResource::make($message)->resolve(),
        ], 201);
    }

    public function typing(TypingRequest $request, Conversation $conversation): Response
    {
        if ($conversation->status->isOpen()) {
            broadcast(new VisitorTyping($conversation, $request->boolean('typing', true)));
        }

        return response()->noContent();
    }

    public function escalate(Conversation $conversation): ConversationResource
    {
        return ConversationResource::make(
            $this->conversations->escalate($conversation)->load(['inbox', 'assignedAgent', 'team'])
        );
    }

    public function close(Conversation $conversation): ConversationResource
    {
        return ConversationResource::make(
            $this->conversations->close($conversation, 'visitor')->load(['inbox', 'assignedAgent', 'team'])
        );
    }
}
