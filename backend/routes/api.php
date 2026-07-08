<?php

use App\Http\Controllers\Api\V1\Agent\AgentStatusController;
use App\Http\Controllers\Api\V1\Agent\ConversationController as AgentConversationController;
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
});
