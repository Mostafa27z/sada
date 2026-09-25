<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AlertRuleController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChatRoomController;
use App\Http\Controllers\Api\V1\ChatMessageController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ComplaintController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\KeywordController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PublicComplaintController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SuperAdminController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantRequestController;
use App\Http\Controllers\Api\V1\TenantUserController;
use App\Http\Controllers\Api\V1\TrendController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
*/

// Health check
Route::get('/health', fn () => response()->json([
    'success' => true,
    'message' => 'Sada API is running',
    'data' => [
        'version' => 'v1',
        'timestamp' => now()->toISOString(),
    ],
]));

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/email/send-otp', [AuthController::class, 'sendEmailOtp']);
    Route::post('/email/verify-otp', [AuthController::class, 'verifyEmailOtp']);
    Route::post('/email/resend', [AuthController::class, 'resendVerification']);
});

// SaaS Plans (Public / Read-only)
Route::get('/plans', [PlanController::class, 'index']);
Route::get('/plans/{slug}', [PlanController::class, 'show']);

// Public Tenant Registration Request submission
Route::post('/tenant-requests', [TenantRequestController::class, 'store']);

// Public QR Code Generator & Customer Complaint Submissions
Route::get('/public/qr-code', [PublicComplaintController::class, 'generateQrCode']);
Route::post('/public/complaints', [PublicComplaintController::class, 'store']);

/*
|--------------------------------------------------------------------------
| Webhook Ingestion Routes (Protected by HMAC Signature)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->middleware(['webhook.signature'])->group(function () {
    Route::post('/ingest-article', [WebhookController::class, 'ingestArticle']);
});

/*
|--------------------------------------------------------------------------
| Authenticated User Routes (Global Context)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Auth management
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify');
        Route::post('/email/resend', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');
    });

    // Profile
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::put('/me/password', [AuthController::class, 'changePassword']);

    // Tenant Workspaces management
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
    Route::put('/tenants/{tenant}', [TenantController::class, 'update']);
    Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switch']);

    // SaaS Plans Management (CRUD & Status Controls - Authenticated Admin)
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{id}', [PlanController::class, 'update']);
    Route::delete('/plans/{id}', [PlanController::class, 'destroy']);
    Route::post('/plans/{id}/toggle-active', [PlanController::class, 'toggleActive']);

    // Platform User Deletion
    Route::delete('/users/{user}', [SuperAdminController::class, 'deleteUser']);

    // System Permissions listing
    Route::get('/permissions', [RoleController::class, 'permissions']);

    // Super Admin System Management Endpoints
    Route::prefix('admin')->group(function () {
        Route::get('/tenants', [SuperAdminController::class, 'tenants']);
        Route::get('/metrics', [SuperAdminController::class, 'metrics']);
        Route::post('/tenants/{tenant}/plan', [SuperAdminController::class, 'assignPlan']);

        // Tenant Join Requests Management
        Route::get('/tenant-requests', [TenantRequestController::class, 'index']);
        Route::post('/tenant-requests/{id}/approve', [TenantRequestController::class, 'approve']);
        Route::post('/tenant-requests/{id}/reject', [TenantRequestController::class, 'reject']);

        // Overview Analytics & Leaderboards
        Route::get('/analytics/company-growth', [SuperAdminController::class, 'companyGrowth']);
        Route::get('/analytics/packages-breakdown', [SuperAdminController::class, 'packagesBreakdown']);
        Route::get('/tenants/at-risk', [SuperAdminController::class, 'atRiskTenants']);
        Route::get('/tenants/top-leaderboard', [SuperAdminController::class, 'topLeaderboard']);

        // Global System Settings & Maintenance
        Route::get('/settings', [SuperAdminController::class, 'getSettings']);
        Route::put('/settings', [SuperAdminController::class, 'updateSettings']);
        Route::post('/settings/maintenance', [SuperAdminController::class, 'toggleMaintenance']);

        // System Backups Management
        Route::get('/backups', [SuperAdminController::class, 'listBackups']);
        Route::post('/backups/trigger', [SuperAdminController::class, 'triggerBackup']);

        // Infrastructure Health & System Alerts
        Route::get('/infrastructure', [SuperAdminController::class, 'infrastructureHealth']);
        Route::get('/system-alerts', [SuperAdminController::class, 'systemAlerts']);

        // Ingestion Analytics
        Route::get('/analytics/ingestion', [SuperAdminController::class, 'ingestionAnalytics']);
    });

    /*
    |--------------------------------------------------------------------------
    | Tenant Context Routes (Requires resolved tenant workspace)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['tenant'])->group(function () {
        // Users in active tenant
        Route::get('/users', [TenantUserController::class, 'index']);
        Route::post('/users/invite', [TenantUserController::class, 'invite'])->middleware('limit:users');
        Route::match(['put', 'patch'], '/users/{user}', [TenantUserController::class, 'update']);
        Route::delete('/users/{user}', [TenantUserController::class, 'remove']);
        Route::post('/users/{user}/roles', [UserRoleController::class, 'assignRole']);

        // Roles in active tenant
        Route::get('/roles', [RoleController::class, 'index']);

        // Subscription & Usage Limits
        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::get('/usage', [SubscriptionController::class, 'usage']);

        // Dashboard & Intelligence
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // Analytics
        Route::get('/analytics/sentiment', [AnalyticsController::class, 'sentiment']);
        Route::get('/analytics/volume', [AnalyticsController::class, 'volume']);
        Route::get('/analytics/top-keywords', [AnalyticsController::class, 'topKeywords']);
        Route::get('/analytics/top-sources', [AnalyticsController::class, 'topSources']);

        // Alert Rules Module
        Route::get('/alert-rules', [AlertRuleController::class, 'index']);
        Route::post('/alert-rules', [AlertRuleController::class, 'store']);
        Route::get('/alert-rules/{id}', [AlertRuleController::class, 'show']);
        Route::put('/alert-rules/{id}', [AlertRuleController::class, 'update']);
        Route::delete('/alert-rules/{id}', [AlertRuleController::class, 'destroy']);

        // Alerts Module
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::post('/alerts/{id}/read', [AlertController::class, 'markAsRead']);
        Route::post('/alerts/{id}/resolve', [AlertController::class, 'resolve']);

        // Reports Module
        Route::get('/reports', [ReportController::class, 'index']);
        Route::post('/reports', [ReportController::class, 'store']);
        Route::get('/reports/{id}', [ReportController::class, 'show']);
        Route::get('/reports/{id}/download', [ReportController::class, 'download']);

        // API Credentials Module
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);

        // Audit Logs Module
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // Keywords Module
        Route::get('/keywords', [KeywordController::class, 'index']);
        Route::post('/keywords', [KeywordController::class, 'store'])->middleware('limit:keywords');
        Route::post('/keywords/trigger-scrape', [KeywordController::class, 'triggerScrape']);
        Route::get('/keywords/{id}', [KeywordController::class, 'show']);
        Route::put('/keywords/{id}', [KeywordController::class, 'update']);
        Route::delete('/keywords/{id}', [KeywordController::class, 'destroy']);
        Route::post('/keywords/{id}/activate', [KeywordController::class, 'activate']);
        Route::post('/keywords/{id}/pause', [KeywordController::class, 'pause']);
        Route::post('/keywords/{id}/archive', [KeywordController::class, 'archive']);

        // Comments Module
        Route::get('/comments', [CommentController::class, 'index']);
        Route::post('/comments/scrape', [CommentController::class, 'scrape']);
        Route::post('/comments/ai-recommendation', [CommentController::class, 'generateAiRecommendation']);

        // Complaints Module
        Route::get('/complaints', [ComplaintController::class, 'index']);
        Route::get('/complaints/summary', [ComplaintController::class, 'summary']);
        Route::post('/complaints/summary/regenerate', [ComplaintController::class, 'regenerateSummary']);
        Route::get('/complaints/{id}', [ComplaintController::class, 'show']);
        Route::patch('/complaints/{id}/status', [ComplaintController::class, 'updateStatus']);
        Route::delete('/complaints/{id}', [ComplaintController::class, 'destroy']);

        // Topic Trends Discovery Module
        Route::prefix('trends')->group(function () {
            Route::get('/', [TrendController::class, 'index']);
            Route::post('/analyze', [TrendController::class, 'analyze']);
            Route::post('/master-post', [TrendController::class, 'generateMasterPost']);
            Route::get('/{id}', [TrendController::class, 'show']);
            Route::delete('/{id}', [TrendController::class, 'destroy']);
        });

        // Sources Module
        Route::get('/sources', [SourceController::class, 'index']);
        Route::post('/sources', [SourceController::class, 'store'])->middleware('limit:sources');
        Route::get('/sources/{id}', [SourceController::class, 'show']);
        Route::put('/sources/{id}', [SourceController::class, 'update']);
        Route::delete('/sources/{id}', [SourceController::class, 'destroy']);

        // Articles Module
        Route::get('/articles', [ArticleController::class, 'index']);
        Route::get('/articles/{id}', [ArticleController::class, 'show']);

        // Collections Module
        Route::get('/collections', [CollectionController::class, 'index']);
        Route::post('/collections', [CollectionController::class, 'store']);
        Route::get('/collections/{id}', [CollectionController::class, 'show']);
        Route::put('/collections/{id}', [CollectionController::class, 'update']);
        Route::delete('/collections/{id}', [CollectionController::class, 'destroy']);
        Route::post('/collections/{id}/sync', [CollectionController::class, 'sync']);
        Route::post('/collections/{id}/articles/{articleId}', [CollectionController::class, 'addArticle']);
        Route::delete('/collections/{id}/articles/{articleId}', [CollectionController::class, 'removeArticle']);

        // Team AI Chat Rooms Module
        Route::get('/chats', [ChatRoomController::class, 'index']);
        Route::post('/chats', [ChatRoomController::class, 'store']);
        Route::get('/chats/{id}', [ChatRoomController::class, 'show']);
        Route::put('/chats/{id}', [ChatRoomController::class, 'update']);
        Route::delete('/chats/{id}', [ChatRoomController::class, 'destroy']);
        Route::post('/chats/{id}/users', [ChatRoomController::class, 'addUsers']);
        Route::delete('/chats/{id}/users/{userId}', [ChatRoomController::class, 'removeUser']);
        Route::get('/chats/{id}/messages', [ChatMessageController::class, 'index']);
        Route::post('/chats/{id}/messages', [ChatMessageController::class, 'send']);
    });
});
