<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\PlatformConnectionController;
use App\Http\Controllers\Api\AiConfigurationController;
use App\Http\Controllers\Api\AiAgentController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\WebChatController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\FacebookAuthController;
use App\Http\Controllers\Api\GoogleCalendarController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\MediaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes with rate limiting for security
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Two-factor authentication verification (during login, no auth required)
Route::post('/two-factor/verify', [SettingsController::class, 'verifyTwoFactor']);

// Team invitation public routes (no auth required)
Route::get('/team/invitation', [TeamController::class, 'getInvitationByToken']);
Route::post('/team/invitation/accept', [TeamController::class, 'acceptInvitation']);

// Facebook OAuth callback (public, session-based)
Route::get('/auth/facebook/callback', [FacebookAuthController::class, 'handleCallback']);

// Google OAuth callback (public, session-based)
Route::get('/auth/google/callback', [GoogleCalendarController::class, 'handleCallback']);

// Webhook routes (public, no auth required)
Route::get('/webhooks/{platform}/{token}', [WebhookController::class, 'verify']);
Route::post('/webhooks/{platform}/{token}', [WebhookController::class, 'handle']);

// Stripe webhook (public, verified by signature)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

// Web Chat Widget routes (public, CORS enabled)
Route::prefix('webchat')->group(function () {
    Route::post('/init', [WebChatController::class, 'init']);
    Route::post('/messages', [WebChatController::class, 'sendMessage']);
    Route::post('/poll', [WebChatController::class, 'getMessages']);
    Route::get('/widget/{widgetId}.js', [WebChatController::class, 'widgetScript']);
    Route::get('/test-ai', [WebChatController::class, 'testAi']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth (no company scoping needed)
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Company-scoped routes with permission checking
    Route::middleware(['company.access', 'permission'])->group(function () {
        // Dashboard
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('/dashboard/recent-conversations', [DashboardController::class, 'recentConversations']);
        Route::get('/dashboard/activity', [DashboardController::class, 'activityFeed']);

        // Conversations
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show']);
        Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'sendMessage']);
        Route::post('/conversations/{id}/assign', [ConversationController::class, 'assign']);
        Route::patch('/conversations/{id}/status', [ConversationController::class, 'updateStatus']);
        Route::patch('/conversations/{id}/ai-handling', [ConversationController::class, 'updateAiHandling']);
        Route::post('/conversations/{id}/transfer-to-ai', [ConversationController::class, 'transferToAi']);
        
        // Conversation Summaries
        Route::get('/conversations/{id}/summary', [ConversationController::class, 'getSummary']);
        Route::post('/conversations/{id}/summary', [ConversationController::class, 'generateSummary']);
        Route::delete('/conversations/{id}/summary', [ConversationController::class, 'deleteSummary']);
        Route::post('/conversations/summaries/batch', [ConversationController::class, 'generateBatchSummaries']);

        // Platform Connections
        Route::get('/platforms', [PlatformConnectionController::class, 'platforms']);
        Route::get('/platform-connections', [PlatformConnectionController::class, 'index']);
        Route::post('/platform-connections', [PlatformConnectionController::class, 'store']);
        Route::get('/platform-connections/{id}', [PlatformConnectionController::class, 'show']);
        Route::put('/platform-connections/{id}', [PlatformConnectionController::class, 'update']);
        Route::delete('/platform-connections/{id}', [PlatformConnectionController::class, 'destroy']);
        Route::post('/platform-connections/{id}/toggle', [PlatformConnectionController::class, 'toggleStatus']);
        Route::post('/platform-connections/{id}/test', [PlatformConnectionController::class, 'testConnection']);

        // Facebook OAuth (for one-click Messenger connection)
        Route::get('/auth/facebook/url', [FacebookAuthController::class, 'getAuthUrl']);
        Route::get('/auth/facebook/pages', [FacebookAuthController::class, 'getPages']);
        Route::post('/auth/facebook/connect', [FacebookAuthController::class, 'connectPage']);

        // Google Calendar OAuth & Configuration
        Route::get('/auth/google/url', [GoogleCalendarController::class, 'getAuthUrl']);
        Route::get('/calendar/calendars', [GoogleCalendarController::class, 'getCalendars']);
        Route::post('/calendar/connect', [GoogleCalendarController::class, 'connectCalendar']);
        Route::get('/calendar/configuration', [GoogleCalendarController::class, 'getConfiguration']);
        Route::put('/calendar/configuration', [GoogleCalendarController::class, 'updateConfiguration']);
        Route::delete('/calendar/disconnect', [GoogleCalendarController::class, 'disconnect']);
        Route::get('/calendar/available-slots', [GoogleCalendarController::class, 'getAvailableSlots']);

        // Appointments
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::get('/appointments/upcoming', [AppointmentController::class, 'upcoming']);
        Route::get('/appointments/available-dates', [AppointmentController::class, 'availableDates']);
        Route::get('/appointments/available-slots', [AppointmentController::class, 'availableSlots']);
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::get('/appointments/{id}', [AppointmentController::class, 'show']);
        Route::put('/appointments/{id}', [AppointmentController::class, 'update']);
        Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);
        Route::post('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule']);

        // Broadcasts
        Route::get('/broadcasts', [BroadcastController::class, 'index']);
        Route::get('/broadcasts/estimate', [BroadcastController::class, 'estimate']);
        Route::post('/broadcasts', [BroadcastController::class, 'store']);
        Route::get('/broadcasts/{id}', [BroadcastController::class, 'show']);
        Route::put('/broadcasts/{id}', [BroadcastController::class, 'update']);
        Route::delete('/broadcasts/{id}', [BroadcastController::class, 'destroy']);
        Route::post('/broadcasts/{id}/send', [BroadcastController::class, 'send']);
        Route::post('/broadcasts/{id}/schedule', [BroadcastController::class, 'schedule']);
        Route::post('/broadcasts/{id}/cancel', [BroadcastController::class, 'cancel']);
        Route::get('/broadcasts/{id}/recipients', [BroadcastController::class, 'recipients']);
        Route::get('/broadcasts/{id}/statistics', [BroadcastController::class, 'statistics']);

        // AI Configuration
        Route::get('/ai-providers', [AiConfigurationController::class, 'providers']);
        Route::get('/ai-providers/{id}/models', [AiConfigurationController::class, 'getModels']);
        Route::get('/ai-configuration', [AiConfigurationController::class, 'show']);
        Route::post('/ai-configuration', [AiConfigurationController::class, 'store']);
        Route::put('/ai-configuration', [AiConfigurationController::class, 'update']);
        Route::post('/ai-configuration/test', [AiConfigurationController::class, 'test']);
        Route::post('/ai-configuration/toggle-auto-respond', [AiConfigurationController::class, 'toggleAutoRespond']);
        Route::get('/ai-configuration/rate-limit', [AiConfigurationController::class, 'rateLimitUsage']);

        // AI Agents (Multiple agents for different situations)
        Route::get('/ai-agents', [AiAgentController::class, 'index']);
        Route::get('/ai-agents/types', [AiAgentController::class, 'types']);
        Route::get('/ai-agents/{id}', [AiAgentController::class, 'show']);
        Route::post('/ai-agents', [AiAgentController::class, 'store']);
        Route::put('/ai-agents/{id}', [AiAgentController::class, 'update']);
        Route::delete('/ai-agents/{id}', [AiAgentController::class, 'destroy']);
        Route::post('/ai-agents/reorder', [AiAgentController::class, 'reorder']);
        Route::post('/ai-agents/{id}/duplicate', [AiAgentController::class, 'duplicate']);
        Route::post('/ai-agents/initialize-defaults', [AiAgentController::class, 'initializeDefaults']);

        // Knowledge Base
        Route::get('/knowledge-base', [KnowledgeBaseController::class, 'index']);
        Route::get('/knowledge-base/categories', [KnowledgeBaseController::class, 'categories']);
        Route::get('/knowledge-base/search', [KnowledgeBaseController::class, 'search']);
        Route::post('/knowledge-base', [KnowledgeBaseController::class, 'store']);
        Route::get('/knowledge-base/{id}', [KnowledgeBaseController::class, 'show']);
        Route::put('/knowledge-base/{id}', [KnowledgeBaseController::class, 'update']);
        Route::delete('/knowledge-base/{id}', [KnowledgeBaseController::class, 'destroy']);
        Route::post('/knowledge-base/{id}/toggle', [KnowledgeBaseController::class, 'toggleStatus']);
        Route::get('/knowledge-base/{id}/download', [KnowledgeBaseController::class, 'download']);

        // Analytics
        Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
        Route::get('/analytics/conversation-trends', [AnalyticsController::class, 'conversationTrends']);
        Route::get('/analytics/sentiment', [AnalyticsController::class, 'sentimentTrends']);
        Route::get('/analytics/satisfaction', [AnalyticsController::class, 'satisfaction']);
        Route::get('/analytics/platform-performance', [AnalyticsController::class, 'platformPerformance']);
        Route::get('/analytics/agent-performance', [AnalyticsController::class, 'agentPerformance']);
        Route::get('/analytics/usage', [AnalyticsController::class, 'usage']);
        Route::get('/analytics/hourly-distribution', [AnalyticsController::class, 'hourlyDistribution']);
        Route::get('/analytics/export', [AnalyticsController::class, 'export']);
        Route::get('/analytics/intent-distribution', [AnalyticsController::class, 'intentDistribution']);
        Route::get('/analytics/intent-metrics', [AnalyticsController::class, 'intentMetrics']);

        // Subscriptions & Billing
        Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans']);
        Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
        Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe']);
        Route::post('/subscriptions/checkout', [SubscriptionController::class, 'checkout']);
        Route::post('/subscriptions/billing-portal', [SubscriptionController::class, 'billingPortal']);
        Route::post('/subscriptions/change-plan', [SubscriptionController::class, 'changePlan']);
        Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('/subscriptions/resume', [SubscriptionController::class, 'resume']);
        Route::get('/subscriptions/usage', [SubscriptionController::class, 'usage']);

        // Customers
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers-stats', [CustomerController::class, 'stats']);
        Route::get('/customers-tags', [CustomerController::class, 'tags']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{id}', [CustomerController::class, 'show']);
        Route::put('/customers/{id}', [CustomerController::class, 'update']);
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
        Route::get('/customers/{id}/conversations', [CustomerController::class, 'conversations']);
        Route::put('/customers/{id}/notes', [CustomerController::class, 'updateNotes']);
        Route::put('/customers/{id}/tags', [CustomerController::class, 'updateTags']);
        Route::post('/customers/{id}/generate-insights', [CustomerController::class, 'generateInsights']);

        // Team Management
        Route::get('/team', [TeamController::class, 'index']);
        Route::get('/team/invitations', [TeamController::class, 'invitations']);
        Route::get('/team/roles', [TeamController::class, 'availableRoles']);
        Route::post('/team/invite', [TeamController::class, 'invite']);
        Route::post('/team/invitations/{id}/resend', [TeamController::class, 'resendInvitation']);
        Route::delete('/team/invitations/{id}', [TeamController::class, 'cancelInvitation']);
        Route::put('/team/members/{memberId}/role', [TeamController::class, 'updateRole']);
        Route::delete('/team/members/{memberId}', [TeamController::class, 'removeMember']);

        // Roles & Permissions
        Route::get('/roles', [RolePermissionController::class, 'index']);
        Route::get('/roles/permissions', [RolePermissionController::class, 'permissions']);
        Route::get('/roles/{id}', [RolePermissionController::class, 'show']);
        Route::put('/roles/{id}/permissions', [RolePermissionController::class, 'updatePermissions']);
        Route::post('/roles', [RolePermissionController::class, 'store']);
        Route::put('/roles/{id}', [RolePermissionController::class, 'update']);
        Route::delete('/roles/{id}', [RolePermissionController::class, 'destroy']);

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
        Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
        Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\NotificationController::class, 'destroy']);

        // Settings - Company
        Route::get('/settings/company', [SettingsController::class, 'companySettings']);
        Route::put('/settings/company', [SettingsController::class, 'updateCompanySettings']);
        Route::post('/settings/company/logo', [SettingsController::class, 'uploadLogo']);
        Route::delete('/settings/company/logo', [SettingsController::class, 'deleteLogo']);
        Route::get('/settings/timezones', [SettingsController::class, 'timezones']);

        // Settings - User Profile & Preferences
        Route::get('/settings/profile', [SettingsController::class, 'userProfile']);
        Route::put('/settings/profile', [SettingsController::class, 'updateUserProfile']);
        Route::put('/settings/preferences', [SettingsController::class, 'updatePreferences']);
        Route::post('/settings/password', [SettingsController::class, 'changePassword']);

        // Settings - Two-Factor Authentication
        Route::post('/settings/2fa/enable', [SettingsController::class, 'enableTwoFactor']);
        Route::post('/settings/2fa/confirm', [SettingsController::class, 'confirmTwoFactor']);
        Route::post('/settings/2fa/disable', [SettingsController::class, 'disableTwoFactor']);
        Route::get('/settings/2fa/recovery-codes', [SettingsController::class, 'getRecoveryCodes']);
        Route::post('/settings/2fa/recovery-codes', [SettingsController::class, 'regenerateRecoveryCodes']);

        // Settings - API Tokens
        Route::get('/settings/api-tokens', [SettingsController::class, 'getApiTokens']);
        Route::post('/settings/api-tokens', [SettingsController::class, 'createApiToken']);
        Route::delete('/settings/api-tokens/{tokenId}', [SettingsController::class, 'deleteApiToken']);

        // Product Categories
        Route::get('/product-categories', [ProductCategoryController::class, 'index']);
        Route::get('/product-categories/tree', [ProductCategoryController::class, 'tree']);
        Route::post('/product-categories', [ProductCategoryController::class, 'store']);
        Route::get('/product-categories/{category}', [ProductCategoryController::class, 'show']);
        Route::put('/product-categories/{category}', [ProductCategoryController::class, 'update']);
        Route::delete('/product-categories/{category}', [ProductCategoryController::class, 'destroy']);
        Route::post('/product-categories/{category}/toggle', [ProductCategoryController::class, 'toggleStatus']);
        Route::post('/product-categories/reorder', [ProductCategoryController::class, 'reorder']);

        // Products
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/stats', [ProductController::class, 'stats']);
        Route::get('/products/search', [ProductController::class, 'search']);
        Route::get('/products/export', [ProductController::class, 'export']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::post('/products/import', [ProductController::class, 'import']);
        Route::post('/products/bulk-delete', [ProductController::class, 'bulkDestroy']);
        Route::post('/products/regenerate-embeddings', [ProductController::class, 'regenerateAllEmbeddings']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{product}/toggle', [ProductController::class, 'toggleStatus']);
        Route::post('/products/{product}/toggle-featured', [ProductController::class, 'toggleFeatured']);
        Route::post('/products/{product}/regenerate-embeddings', [ProductController::class, 'regenerateEmbeddings']);
        Route::post('/products/upload-image', [ProductController::class, 'uploadImage']);
        Route::post('/products/delete-image', [ProductController::class, 'deleteImage']);

        // Media Library
        Route::prefix('media')->group(function () {
            Route::get('/', [MediaController::class, 'index']);
            Route::post('/', [MediaController::class, 'store']);
            Route::post('/bulk-upload', [MediaController::class, 'bulkUpload']);
            Route::post('/import-from-url', [MediaController::class, 'importFromUrl']);
            Route::get('/storage-usage', [MediaController::class, 'storageUsage']);
            Route::get('/folders', [MediaController::class, 'folders']);
            Route::get('/collection/{collection}', [MediaController::class, 'byCollection']);
            Route::get('/for-model', [MediaController::class, 'forModel']);
            Route::post('/reorder', [MediaController::class, 'reorder']);

            // Individual media routes
            Route::get('/{media}', [MediaController::class, 'show'])->where('media', '[0-9]+');
            Route::put('/{media}', [MediaController::class, 'update'])->where('media', '[0-9]+');
            Route::delete('/{media}', [MediaController::class, 'destroy'])->where('media', '[0-9]+');
            Route::post('/{media}/copy', [MediaController::class, 'copy'])->where('media', '[0-9]+');
            Route::post('/{media}/move', [MediaController::class, 'moveToFolder'])->where('media', '[0-9]+');
            Route::post('/{media}/attach', [MediaController::class, 'attach'])->where('media', '[0-9]+');
            Route::post('/{media}/detach', [MediaController::class, 'detach'])->where('media', '[0-9]+');
            Route::post('/{media}/analyze', [MediaController::class, 'analyze'])->where('media', '[0-9]+');

            // Bulk operations
            Route::post('/bulk-delete', [MediaController::class, 'bulkDestroy']);
        });

        // Activity Logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/activity-logs/action-types', [ActivityLogController::class, 'actionTypes']);
        Route::get('/activity-logs/stats', [ActivityLogController::class, 'stats']);

        // Workflows
        Route::prefix('workflows')->group(function () {
            // Workflow CRUD
            Route::get('/', [WorkflowController::class, 'index']);
            Route::post('/', [WorkflowController::class, 'store']);
            Route::get('/statistics', [WorkflowController::class, 'statistics']);

            // Workflow steps (static routes before {id})
            Route::put('/steps/{stepId}', [WorkflowController::class, 'updateStep']);
            Route::delete('/steps/{stepId}', [WorkflowController::class, 'deleteStep']);

            // Workflow executions (static routes before {id})
            Route::get('/executions', [WorkflowController::class, 'executions']);
            Route::get('/executions/{id}', [WorkflowController::class, 'executionDetails']);
            Route::post('/executions/{id}/cancel', [WorkflowController::class, 'cancelExecution']);
            Route::post('/executions/{id}/retry', [WorkflowController::class, 'retryExecution']);

            // Workflow resource routes (wildcard {id} after static routes)
            Route::get('/{id}', [WorkflowController::class, 'show']);
            Route::put('/{id}', [WorkflowController::class, 'update']);
            Route::delete('/{id}', [WorkflowController::class, 'destroy']);
            Route::post('/{id}/activate', [WorkflowController::class, 'activate']);
            Route::post('/{id}/deactivate', [WorkflowController::class, 'deactivate']);
            Route::post('/{id}/duplicate', [WorkflowController::class, 'duplicate']);
            Route::post('/{id}/test', [WorkflowController::class, 'test']);
            Route::post('/{id}/steps', [WorkflowController::class, 'addStep']);
        });

        // Dev Mail Viewer (local environment only)
        Route::prefix('dev')->group(function () {
            Route::get('/mail', [\App\Http\Controllers\Api\DevMailController::class, 'index']);
            Route::get('/mail/{index}', [\App\Http\Controllers\Api\DevMailController::class, 'show']);
            Route::delete('/mail', [\App\Http\Controllers\Api\DevMailController::class, 'clear']);
        });
    });
});
