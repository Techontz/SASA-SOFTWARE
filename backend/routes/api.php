<?php

use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommitmentController;
use App\Http\Controllers\Api\V1\ConcernController;
use App\Http\Controllers\Api\V1\ConfigurationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EngagementController;
use App\Http\Controllers\Api\V1\EngagementPlanController;
use App\Http\Controllers\Api\V1\GrievanceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SavedViewController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\StakeholderController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\VoiceWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SASA API v1
|--------------------------------------------------------------------------
|
| Conventions: versioned, tenant scope derived from the token (never from a
| request parameter), consistent filter syntax, structured validation errors,
| and idempotency keys on the intake and sync endpoints.
|
| `project` middleware resolves and validates the project from the
| X-Sasa-Project header against the user's own membership.
|
*/

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------- public
    Route::get('health', HealthController::class)->name('health');

    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Telephony webhooks: signature-verified, not session-authenticated.
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('voice/{projectCode}/webhook', [VoiceWebhookController::class, 'handle']);
        Route::get('voice/{projectCode}/script', [VoiceWebhookController::class, 'script']);
    });

    // Signed, short-lived attachment downloads.
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    // ------------------------------------------------------------- protected
    Route::middleware('auth:sanctum')->group(function () {

        // Identity and project switching need no project context.
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

        // -------------------------------------------------- project-scoped
        Route::middleware('project')->group(function () {

            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::patch('projects/{project}', [ProjectController::class, 'update']);

            // --- Dashboards -------------------------------------------------
            Route::prefix('dashboard')->group(function () {
                Route::get('landing', [DashboardController::class, 'landing']);
                Route::get('executive', [DashboardController::class, 'executive']);
                Route::get('timeliness', [DashboardController::class, 'timeliness']);
                Route::get('disaggregation', [DashboardController::class, 'disaggregation']);
                Route::get('severity', [DashboardController::class, 'severity']);
                Route::get('engagement', [DashboardController::class, 'engagement']);
                Route::get('definitions', [DashboardController::class, 'definitions']);
            });

            // --- Module 1 · Stakeholder register ------------------------------
            Route::prefix('stakeholders')->group(function () {
                Route::get('/', [StakeholderController::class, 'index']);
                Route::post('/', [StakeholderController::class, 'store']);
                Route::get('duplicates', [StakeholderController::class, 'duplicates']);
                Route::get('priority-model', [StakeholderController::class, 'priorityModel']);
                Route::get('export', [StakeholderController::class, 'export']);
                Route::get('{stakeholder}', [StakeholderController::class, 'show']);
                Route::patch('{stakeholder}', [StakeholderController::class, 'update']);
                Route::delete('{stakeholder}', [StakeholderController::class, 'archive']);
                Route::post('{stakeholder}/restore', [StakeholderController::class, 'restore']);
                Route::get('{stakeholder}/timeline', [StakeholderController::class, 'timeline']);
                Route::post('{stakeholder}/priority', [StakeholderController::class, 'overridePriority']);
                Route::post('{stakeholder}/recalculate-priority', [StakeholderController::class, 'recalculatePriority']);
                Route::post('{stakeholder}/merge', [StakeholderController::class, 'merge']);
            });

            // --- Module 2 · Engagement planning -------------------------------
            Route::prefix('engagement-plans')->group(function () {
                Route::get('/', [EngagementPlanController::class, 'index']);
                Route::post('/', [EngagementPlanController::class, 'store']);
                Route::get('calendar', [EngagementPlanController::class, 'calendar']);
                Route::get('{engagementPlan}', [EngagementPlanController::class, 'show']);
                Route::patch('{engagementPlan}', [EngagementPlanController::class, 'update']);
                Route::post('{engagementPlan}/status', [EngagementPlanController::class, 'changeStatus']);
                Route::delete('{engagementPlan}', [EngagementPlanController::class, 'archive']);
            });

            // --- Module 2 · Engagement logging --------------------------------
            Route::prefix('engagements')->group(function () {
                Route::get('/', [EngagementController::class, 'index']);
                Route::post('/', [EngagementController::class, 'store']);
                Route::get('export', [EngagementController::class, 'export']);
                Route::get('{engagement}', [EngagementController::class, 'show']);
                Route::patch('{engagement}', [EngagementController::class, 'update']);
                Route::delete('{engagement}', [EngagementController::class, 'archive']);
            });

            // --- Module 2 · Concerns ------------------------------------------
            Route::prefix('concerns')->group(function () {
                Route::get('/', [ConcernController::class, 'index']);
                Route::post('/', [ConcernController::class, 'store']);
                Route::get('{concern}', [ConcernController::class, 'show']);
                Route::patch('{concern}', [ConcernController::class, 'update']);
                Route::delete('{concern}', [ConcernController::class, 'archive']);
                // Escalation carries the concern's substance across — no retyping.
                Route::get('{concern}/escalation-draft', [ConcernController::class, 'escalationDraft']);
                Route::post('{concern}/escalate', [ConcernController::class, 'escalate']);
            });

            // --- Module 2 · Commitments register ------------------------------
            Route::prefix('commitments')->group(function () {
                Route::get('/', [CommitmentController::class, 'index']);
                Route::post('/', [CommitmentController::class, 'store']);
                Route::get('export', [CommitmentController::class, 'export']);
                Route::get('{commitment}', [CommitmentController::class, 'show']);
                Route::patch('{commitment}', [CommitmentController::class, 'update']);
                Route::post('{commitment}/status', [CommitmentController::class, 'changeStatus']);
                Route::post('{commitment}/verify', [CommitmentController::class, 'verify']);
                Route::delete('{commitment}', [CommitmentController::class, 'archive']);
            });

            // --- Module 3 · Grievance management ------------------------------
            Route::prefix('grievances')->group(function () {
                Route::get('/', [GrievanceController::class, 'index']);
                Route::post('/', [GrievanceController::class, 'store']);
                Route::get('export', [GrievanceController::class, 'export']);
                Route::get('{grievance}', [GrievanceController::class, 'show']);
                Route::patch('{grievance}', [GrievanceController::class, 'update']);

                Route::post('{grievance}/classify', [GrievanceController::class, 'classify']);
                Route::post('{grievance}/assign', [GrievanceController::class, 'assign']);
                Route::post('{grievance}/acknowledge', [GrievanceController::class, 'acknowledge']);
                Route::post('{grievance}/investigation/start', [GrievanceController::class, 'startInvestigation']);
                Route::post('{grievance}/investigation', [GrievanceController::class, 'recordInvestigation']);
                Route::post('{grievance}/resolve', [GrievanceController::class, 'resolve']);
                Route::post('{grievance}/complainant-response', [GrievanceController::class, 'recordComplainantResponse']);
                Route::post('{grievance}/close', [GrievanceController::class, 'close']);
                Route::post('{grievance}/reopen', [GrievanceController::class, 'reopen']);
                Route::post('{grievance}/escalate', [GrievanceController::class, 'escalate']);
                Route::post('{grievance}/withdraw', [GrievanceController::class, 'withdraw']);
                Route::post('{grievance}/follow-ups', [GrievanceController::class, 'addFollowUp']);
                Route::post('{grievance}/communications', [GrievanceController::class, 'sendCommunication']);
            });

            // --- Search and saved views ---------------------------------------
            Route::get('search', SearchController::class);
            Route::apiResource('saved-views', SavedViewController::class)->except(['show']);

            // --- Locations ------------------------------------------------------
            Route::get('locations', [LocationController::class, 'index']);
            Route::get('locations/tree', [LocationController::class, 'tree']);
            Route::post('locations', [LocationController::class, 'store']);
            Route::patch('locations/{location}', [LocationController::class, 'update']);

            // --- Attachments -----------------------------------------------------
            Route::get('attachments', [AttachmentController::class, 'index']);
            Route::post('attachments', [AttachmentController::class, 'store']);
            Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

            // --- Offline sync -----------------------------------------------------
            Route::prefix('sync')->group(function () {
                Route::get('bootstrap', [SyncController::class, 'bootstrap']);
                Route::post('push', [SyncController::class, 'push']);
                Route::get('pull', [SyncController::class, 'pull']);
                Route::get('status', [SyncController::class, 'status']);
                Route::get('conflicts', [SyncController::class, 'conflicts']);
                Route::post('conflicts/{conflict}/resolve', [SyncController::class, 'resolveConflict']);
            });

            // --- Reporting ---------------------------------------------------------
            Route::prefix('reports')->group(function () {
                Route::get('templates', [ReportController::class, 'templates']);
                Route::get('definitions', [ReportController::class, 'definitions']);
                Route::post('definitions', [ReportController::class, 'storeDefinition']);
                Route::get('/', [ReportController::class, 'index']);
                Route::post('/', [ReportController::class, 'generate']);
                Route::get('{report}', [ReportController::class, 'show']);
                Route::get('{report}/download', [ReportController::class, 'download']);
            });

            // --- Import -------------------------------------------------------------
            Route::prefix('imports')->group(function () {
                Route::get('/', [ImportController::class, 'index']);
                Route::get('template/{entity}', [ImportController::class, 'template']);
                Route::post('/', [ImportController::class, 'upload']);
                Route::get('{importJob}', [ImportController::class, 'show']);
                Route::post('{importJob}/validate', [ImportController::class, 'validateJob']);
                Route::post('{importJob}/commit', [ImportController::class, 'commit']);
            });

            // --- Configuration --------------------------------------------------------
            Route::prefix('configuration')->group(function () {
                Route::get('/', [ConfigurationController::class, 'index']);
                Route::put('/', [ConfigurationController::class, 'update']);
                Route::get('categories', [ConfigurationController::class, 'categories']);
                Route::post('categories', [ConfigurationController::class, 'storeCategory']);
                Route::patch('categories/{category}', [ConfigurationController::class, 'updateCategory']);
                Route::post('categories/{category}/retire', [ConfigurationController::class, 'retireCategory']);
                Route::get('sla-policies', [ConfigurationController::class, 'slaPolicies']);
                Route::post('sla-policies', [ConfigurationController::class, 'storeSlaPolicy']);
                Route::delete('sla-policies/{policy}', [ConfigurationController::class, 'deleteSlaPolicy']);
                Route::get('calendars', [ConfigurationController::class, 'calendars']);
                Route::patch('calendars/{calendar}', [ConfigurationController::class, 'updateCalendar']);
                Route::post('calendars/{calendar}/holidays', [ConfigurationController::class, 'storeHoliday']);
                Route::delete('holidays/{holiday}', [ConfigurationController::class, 'deleteHoliday']);
                Route::get('roles', [ConfigurationController::class, 'roles']);
                Route::get('permissions', [ConfigurationController::class, 'permissions']);
                Route::get('notification-rules', [ConfigurationController::class, 'notificationRules']);
                Route::put('notification-rules', [ConfigurationController::class, 'updateNotificationRule']);
            });

            // --- Members -----------------------------------------------------------------
            Route::get('members', [ProjectMemberController::class, 'index']);
            Route::post('members', [ProjectMemberController::class, 'store']);
            Route::patch('members/{membership}', [ProjectMemberController::class, 'update']);
            Route::delete('members/{membership}', [ProjectMemberController::class, 'destroy']);
            Route::post('members/{membership}/reset-password', [ProjectMemberController::class, 'resetPassword']);

            // --- Audit -------------------------------------------------------------------
            Route::prefix('audit')->group(function () {
                Route::get('/', [AuditController::class, 'index']);
                Route::get('actions', [AuditController::class, 'actions']);
                Route::get('sensitive-views', [AuditController::class, 'sensitiveViews']);
                Route::get('{type}/{id}', [AuditController::class, 'forEntity']);
            });

            // --- AI ----------------------------------------------------------------------
            Route::prefix('ai')->group(function () {
                Route::get('status', [AiController::class, 'status']);
                Route::get('pending', [AiController::class, 'pending']);
                Route::get('accuracy', [AiController::class, 'accuracy']);
                Route::get('voice-calls', [AiController::class, 'voiceCalls']);
                Route::post('suggestions/{suggestion}/review', [AiController::class, 'review']);
                Route::post('grievances/{grievance}/suggest', [AiController::class, 'suggest']);
            });
        });
    });
});
