<?php

use App\Http\Controllers\Api\V1\Admin\AgentDirectoryController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\CannedResponseController as AdminCannedResponseController;
use App\Http\Controllers\Api\V1\Admin\FaqGroupController;
use App\Http\Controllers\Api\V1\Admin\InboxController as AdminInboxController;
use App\Http\Controllers\Api\V1\Admin\LicenseController;
use App\Http\Controllers\Api\V1\Admin\MitelQueueController;
use App\Http\Controllers\Api\V1\Admin\RoutingRuleController;
use App\Http\Controllers\Api\V1\Admin\TeamController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\WebhookController;
use App\Http\Controllers\Api\V1\Agent\AgentStatusController;
use App\Http\Controllers\Api\V1\Agent\AppConfigController;
use App\Http\Controllers\Api\V1\Agent\CannedResponseController as AgentCannedResponseController;
use App\Http\Controllers\Api\V1\Agent\ConversationController as AgentConversationController;
use App\Http\Controllers\Api\V1\Agent\InboxController as AgentInboxController;
use App\Http\Controllers\Api\V1\Agent\TranslationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\ChatSettingsController;
use App\Http\Controllers\Api\V1\Public\AppConfigController as PublicAppConfigController;
use App\Http\Controllers\Api\V1\Public\AttachmentController as PublicAttachmentController;
use App\Http\Controllers\Api\V1\Public\ConversationController as PublicConversationController;
use App\Http\Controllers\Api\V1\Public\FaqController as PublicFaqController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public widget API — no authentication; the conversation UUID is the capability.
    Route::prefix('public')->group(function () {
        Route::post('/inboxes/{inbox:slug}/conversations', [PublicConversationController::class, 'store'])
            ->middleware('throttle:chat-start');
        Route::get('/inboxes/{inbox:slug}/faqs', [PublicFaqController::class, 'index']);
        Route::get('/app-config', [PublicAppConfigController::class, 'show']);
        Route::get('/conversations/{conversation}', [PublicConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [PublicConversationController::class, 'message']);
        Route::post('/conversations/{conversation}/attachments', [PublicConversationController::class, 'attachment']);
        Route::post('/conversations/{conversation}/escalate', [PublicConversationController::class, 'escalate']);
        Route::post('/conversations/{conversation}/close', [PublicConversationController::class, 'close']);
        // uuid is the download capability, so this stays public (used in <img src>).
        Route::get('/attachments/{attachment}', [PublicAttachmentController::class, 'show']);
    });

    // Authentication
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });

    // Agent inbox API. seat.valid strictly enforces one-license-per-agent:
    // an agent whose seat has lapsed is blocked here and logged out by the
    // dashboard. It passes non-agents and unconfigured installs.
    Route::prefix('agent')->middleware(['auth:sanctum', 'seat.valid'])->group(function () {
        Route::get('/app-config', [AppConfigController::class, 'show']);
        Route::post('/translate', [TranslationController::class, 'draft'])
            ->middleware('permission:messages.send');
        Route::post('/conversations/{conversation}/messages/{message}/translate', [TranslationController::class, 'message'])
            ->middleware('permission:messages.send')
            ->scopeBindings();
        Route::get('/inboxes', [AgentInboxController::class, 'index'])
            ->middleware('permission:conversations.view_own');
        Route::get('/conversations', [AgentConversationController::class, 'index'])
            ->middleware('permission:conversations.view_own');
        Route::get('/conversations/{conversation}', [AgentConversationController::class, 'show'])
            ->middleware('permission:conversations.view_own');
        Route::get('/conversations/{conversation}/history', [AgentConversationController::class, 'history'])
            ->middleware('permission:conversations.view_own');
        Route::post('/conversations/{conversation}/accept', [AgentConversationController::class, 'accept'])
            ->middleware('permission:conversations.accept');
        // Used by the embedded chat window (MiCC Ignite) to take ownership of a
        // conversation MiCC has already routed to the agent.
        Route::post('/conversations/{conversation}/claim', [AgentConversationController::class, 'claim'])
            ->middleware('permission:conversations.accept');
        Route::post('/conversations/{conversation}/close', [AgentConversationController::class, 'close'])
            ->middleware('permission:conversations.close');
        Route::post('/conversations/{conversation}/messages', [AgentConversationController::class, 'message'])
            ->middleware('permission:messages.send');
        Route::post('/conversations/{conversation}/attachments', [AgentConversationController::class, 'attachment'])
            ->middleware('permission:messages.send');
        Route::post('/conversations/{conversation}/notes', [AgentConversationController::class, 'note'])
            ->middleware('permission:messages.send');
        Route::post('/conversations/{conversation}/reroute', [AgentConversationController::class, 'reroute'])
            ->middleware('permission:conversations.assign');
        Route::post('/conversations/{conversation}/typing', [AgentConversationController::class, 'typing'])
            ->middleware('permission:messages.send');
        Route::get('/canned-responses', [AgentCannedResponseController::class, 'index'])
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

        // User management is license-gated; the seats endpoint stays outside
        // the gate so the dashboard can explain WHY access is blocked.
        Route::middleware(['permission:agents.manage', 'license.valid'])->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::get('/roles', [UserController::class, 'roles']);
            Route::get('/agents', [AgentDirectoryController::class, 'index']);
        });
        Route::get('/license/seats', [LicenseController::class, 'seats'])
            ->middleware('permission:agents.manage');

        Route::middleware('permission:license.manage')->group(function () {
            Route::get('/license', [LicenseController::class, 'show']);
            Route::post('/license/sync', [LicenseController::class, 'sync']);
            Route::post('/licenses/{licenseRecord}/release', [LicenseController::class, 'release']);
        });

        Route::middleware('permission:settings.manage')->group(function () {
            Route::get('/chat-settings', [ChatSettingsController::class, 'show']);
            Route::put('/chat-settings', [ChatSettingsController::class, 'update']);
        });

        Route::middleware('permission:faqs.manage')->group(function () {
            Route::get('/faqs', [FaqGroupController::class, 'index']);
            Route::post('/faqs', [FaqGroupController::class, 'store']);
            Route::put('/faqs/{translationGroup}', [FaqGroupController::class, 'update']);
            Route::delete('/faqs/{translationGroup}', [FaqGroupController::class, 'destroy']);
        });

        Route::middleware('permission:canned_responses.manage')->group(function () {
            Route::get('/canned-responses', [AdminCannedResponseController::class, 'index']);
            Route::post('/canned-responses', [AdminCannedResponseController::class, 'store']);
            Route::put('/canned-responses/{cannedResponse}', [AdminCannedResponseController::class, 'update']);
            Route::delete('/canned-responses/{cannedResponse}', [AdminCannedResponseController::class, 'destroy']);
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
