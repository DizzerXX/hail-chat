<?php

use App\Http\Controllers\Api\V1\Admin\AgentDirectoryController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\FaqGroupController;
use App\Http\Controllers\Api\V1\Admin\InboxController as AdminInboxController;
use App\Http\Controllers\Api\V1\Admin\MitelQueueController;
use App\Http\Controllers\Api\V1\Admin\RoutingRuleController;
use App\Http\Controllers\Api\V1\Admin\TeamController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\WebhookController;
use App\Http\Controllers\Api\V1\Agent\AgentStatusController;
use App\Http\Controllers\Api\V1\Agent\ConversationController as AgentConversationController;
use App\Http\Controllers\Api\V1\Agent\InboxController as AgentInboxController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Public\ConversationController as PublicConversationController;
use App\Http\Controllers\Api\V1\Public\FaqController as PublicFaqController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public widget API — no authentication; the conversation UUID is the capability.
    Route::prefix('public')->group(function () {
        Route::post('/inboxes/{inbox:slug}/conversations', [PublicConversationController::class, 'store'])
            ->middleware('throttle:chat-start');
        Route::get('/inboxes/{inbox:slug}/faqs', [PublicFaqController::class, 'index']);
        Route::get('/conversations/{conversation}', [PublicConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [PublicConversationController::class, 'message']);
        Route::post('/conversations/{conversation}/escalate', [PublicConversationController::class, 'escalate']);
        Route::post('/conversations/{conversation}/close', [PublicConversationController::class, 'close']);
    });

    // Authentication
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });

    // Agent inbox API
    Route::prefix('agent')->middleware('auth:sanctum')->group(function () {
        Route::get('/inboxes', [AgentInboxController::class, 'index'])
            ->middleware('permission:conversations.view_own');
        Route::get('/conversations', [AgentConversationController::class, 'index'])
            ->middleware('permission:conversations.view_own');
        Route::get('/conversations/{conversation}', [AgentConversationController::class, 'show'])
            ->middleware('permission:conversations.view_own');
        Route::post('/conversations/{conversation}/accept', [AgentConversationController::class, 'accept'])
            ->middleware('permission:conversations.accept');
        Route::post('/conversations/{conversation}/close', [AgentConversationController::class, 'close'])
            ->middleware('permission:conversations.close');
        Route::post('/conversations/{conversation}/messages', [AgentConversationController::class, 'message'])
            ->middleware('permission:messages.send');
        Route::post('/conversations/{conversation}/reroute', [AgentConversationController::class, 'reroute'])
            ->middleware('permission:conversations.assign');
        Route::post('/conversations/{conversation}/typing', [AgentConversationController::class, 'typing'])
            ->middleware('permission:messages.send');
        Route::put('/status', [AgentStatusController::class, 'update']);
    });

    // Admin API
    Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:inboxes.manage')->group(function () {
            Route::get('/inboxes', [AdminInboxController::class, 'index']);
            Route::post('/inboxes', [AdminInboxController::class, 'store']);
            Route::get('/inboxes/{inbox}', [AdminInboxController::class, 'show']);
            Route::put('/inboxes/{inbox}', [AdminInboxController::class, 'update']);
            Route::delete('/inboxes/{inbox}', [AdminInboxController::class, 'destroy']);
            Route::get('/mitel/queues', [MitelQueueController::class, 'index']);
        });

        Route::middleware('permission:teams.manage')->group(function () {
            Route::get('/teams', [TeamController::class, 'index']);
            Route::post('/teams', [TeamController::class, 'store']);
            Route::put('/teams/{team}', [TeamController::class, 'update']);
            Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
            Route::put('/teams/{team}/members', [TeamController::class, 'members']);
        });

        Route::middleware('permission:agents.manage')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::get('/roles', [UserController::class, 'roles']);
            Route::get('/agents', [AgentDirectoryController::class, 'index']);
        });

        Route::middleware('permission:faqs.manage')->group(function () {
            Route::get('/faqs', [FaqGroupController::class, 'index']);
            Route::post('/faqs', [FaqGroupController::class, 'store']);
            Route::put('/faqs/{translationGroup}', [FaqGroupController::class, 'update']);
            Route::delete('/faqs/{translationGroup}', [FaqGroupController::class, 'destroy']);
        });

        Route::middleware('permission:webhooks.manage')->group(function () {
            Route::get('/webhooks', [WebhookController::class, 'index']);
            Route::post('/webhooks', [WebhookController::class, 'store']);
            Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
            Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
        });

        Route::middleware('permission:routing.manage')->group(function () {
            Route::get('/inboxes/{inbox}/routing-rules', [RoutingRuleController::class, 'index']);
            Route::post('/inboxes/{inbox}/routing-rules', [RoutingRuleController::class, 'store']);
            Route::put('/routing-rules/{rule}', [RoutingRuleController::class, 'update']);
            Route::delete('/routing-rules/{rule}', [RoutingRuleController::class, 'destroy']);
        });

        Route::get('/analytics', [AnalyticsController::class, 'index'])
            ->middleware('permission:analytics.view');
    });
});
