<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\ConversationStatus;
use App\Exceptions\TranslationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Translation\MessageTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TranslationController extends Controller
{
    public function __construct(private MessageTranslator $translator)
    {
    }

    /**
     * Manually translate a stored message (also stores + broadcasts it).
     */
    public function message(Request $request, Conversation $conversation, Message $message): MessageResource
    {
        $this->assertAvailable();
        $this->authorizeView($request, $conversation);

        $validated = $request->validate([
            'target' => ['sometimes', Rule::in(config('tdechat.languages'))],
        ]);

        try {
            $translated = $this->translator->translateMessage($message, $validated['target'] ?? null);
        } catch (TranslationException $exception) {
            abort(502, $exception->getMessage());
        }

        return MessageResource::make($translated->load('agent'));
    }

    /**
     * Translate a composer draft (nothing stored).
     */
    public function draft(Request $request): JsonResponse
    {
        $this->assertAvailable();

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'target' => ['required', Rule::in(config('tdechat.languages'))],
        ]);

        try {
            $translation = $this->translator->translateText($validated['text'], $validated['target']);
        } catch (TranslationException $exception) {
            abort(502, $exception->getMessage());
        }

        return response()->json(['data' => [
            'translation' => $translation,
            'target' => $validated['target'],
        ]]);
    }

    private function assertAvailable(): void
    {
        abort_unless($this->translator->enabled(), 404, 'Translation is not enabled.');
        abort_unless(
            $this->translator->ready(),
            503,
            'The translation server is not configured yet.',
        );
    }

    private function authorizeView(Request $request, Conversation $conversation): void
    {
        $user = $request->user();

        if ($user->hasPermission('conversations.view_all')) {
            return;
        }

        $agent = $user->agent;
        abort_unless($agent !== null, 403, 'This account has no agent profile.');

        $isMine = $conversation->assigned_agent_id === $agent->id;
        $isQueueVisible = $conversation->status === ConversationStatus::AwaitingAcceptance
            && $conversation->assigned_agent_id === null
            && ($conversation->team_id === null || $agent->teams()->whereKey($conversation->team_id)->exists());

        abort_unless($isMine || $isQueueVisible, 403, 'You cannot view this conversation.');
    }
}
