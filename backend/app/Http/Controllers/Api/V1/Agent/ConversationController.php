<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\ConversationStatus;
use App\Events\AgentTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\RerouteConversationRequest;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\TypingRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Team;
use App\Services\ConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function __construct(private ConversationService $conversations)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'scope' => ['sometimes', Rule::in(['mine', 'queue', 'all'])],
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'inbox_id' => ['sometimes', 'integer'],
        ]);

        $scope = $validated['scope'] ?? 'mine';

        $query = Conversation::query()
            ->with(['inbox', 'assignedAgent', 'team'])
            ->orderByDesc('last_activity_at');

        match ($scope) {
            'mine' => $query->where('assigned_agent_id', $this->requireAgent($request)->id),
            'queue' => $this->scopeQueue($query, $this->requireAgent($request)),
            'all' => abort_unless($request->user()->hasPermission('conversations.view_all'), 403),
        };

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['inbox_id'])) {
            $query->where('inbox_id', $validated['inbox_id']);
        }

        return ConversationResource::collection($query->paginate(25));
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorizeView($request, $conversation);

        return ConversationResource::make(
            $conversation->load(['inbox', 'assignedAgent', 'team', 'messages.agent'])
        );
    }

    public function accept(Request $request, Conversation $conversation): ConversationResource
    {
        $accepted = $this->conversations->accept($conversation, $this->requireAgent($request));

        return ConversationResource::make($accepted->load(['inbox', 'assignedAgent', 'team']));
    }

    public function close(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorizeHandle($request, $conversation);

        $closed = $this->conversations->close($conversation, 'agent');

        return ConversationResource::make($closed->load(['inbox', 'assignedAgent', 'team']));
    }

    public function message(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->conversations->agentMessage(
            $conversation,
            $this->requireAgent($request),
            $request->validated('body'),
        );

        return MessageResource::make($message->load('agent'))->response()->setStatusCode(201);
    }

    public function reroute(RerouteConversationRequest $request, Conversation $conversation): ConversationResource
    {
        $team = $request->filled('team_id') ? Team::findOrFail($request->validated('team_id')) : null;
        $agent = $request->filled('agent_id') ? Agent::findOrFail($request->validated('agent_id')) : null;

        $rerouted = $this->conversations->reroute($conversation, $team, $agent);

        return ConversationResource::make($rerouted->load(['inbox', 'assignedAgent', 'team']));
    }

    public function typing(TypingRequest $request, Conversation $conversation): Response
    {
        $agent = $this->requireAgent($request);

        $this->authorizeHandle($request, $conversation);

        broadcast(new AgentTyping($conversation, $agent, $request->boolean('typing', true)));

        return response()->noContent();
    }

    private function scopeQueue(Builder $query, Agent $agent): Builder
    {
        return $query
            ->where('status', ConversationStatus::AwaitingAcceptance)
            ->where(function (Builder $query) use ($agent) {
                $query
                    ->where('assigned_agent_id', $agent->id)
                    ->orWhere(function (Builder $query) use ($agent) {
                        $query
                            ->whereNull('assigned_agent_id')
                            ->where(function (Builder $query) use ($agent) {
                                $query
                                    ->whereNull('team_id')
                                    ->orWhereIn('team_id', $agent->teams()->select('teams.id'));
                            });
                    });
            });
    }

    private function requireAgent(Request $request): Agent
    {
        $agent = $request->user()->agent;

        abort_unless($agent !== null, 403, 'This account has no agent profile.');

        return $agent;
    }

    private function authorizeView(Request $request, Conversation $conversation): void
    {
        $user = $request->user();

        if ($user->hasPermission('conversations.view_all')) {
            return;
        }

        $agent = $this->requireAgent($request);

        $isMine = $conversation->assigned_agent_id === $agent->id;

        $isQueueVisible = $conversation->status === ConversationStatus::AwaitingAcceptance
            && $conversation->assigned_agent_id === null
            && ($conversation->team_id === null || $agent->teams()->whereKey($conversation->team_id)->exists());

        abort_unless($isMine || $isQueueVisible, 403, 'You cannot view this conversation.');
    }

    private function authorizeHandle(Request $request, Conversation $conversation): void
    {
        if ($request->user()->hasPermission('conversations.assign')) {
            return;
        }

        $agent = $this->requireAgent($request);

        abort_unless(
            $conversation->assigned_agent_id === $agent->id,
            403,
            'This conversation is assigned to another agent.',
        );
    }
}
