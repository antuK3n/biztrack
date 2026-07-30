<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BusinessStatusController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfficeFormController;
use App\Http\Controllers\Api\OfficerRequestController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PermitController;
use App\Http\Controllers\Api\PriorPermitController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\VerifyController;
use Illuminate\Support\Facades\Route;

/*
 * BizTrack workflow REST surface (mounted at /api/v1 — see bootstrap/app.php).
 * Auth: auth:sanctum + permission:{name}. The public verify route is OUTSIDE
 * the auth group. Responses: { data, meta? }.
 */

// --- PUBLIC ------------------------------------------------------------------
Route::get('verify/{permit_number}', [VerifyController::class, 'show']);

// --- Authenticated -----------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // Reference lookups (auth only; power the wizard)
    Route::prefix('reference')->group(function () {
        Route::get('barangays', [ReferenceController::class, 'barangays']);
        Route::get('psic-codes', [ReferenceController::class, 'psicCodes']);
        Route::get('departments', [ReferenceController::class, 'departments']);
        Route::get('document-types', [ReferenceController::class, 'documentTypes']);
        Route::get('permit-types', [ReferenceController::class, 'permitTypes']);
    });

    // Businesses (owner: business.manage_own)
    Route::middleware('permission:business.manage_own')->group(function () {
        Route::get('businesses', [BusinessController::class, 'index']);
        Route::post('businesses', [BusinessController::class, 'store']);
        Route::put('businesses/{business}', [BusinessController::class, 'update']);
    });
    // Show allowed for owner OR officer with application.view_all (checked in controller)
    Route::get('businesses/{business}', [BusinessController::class, 'show']);
    // Renewal/amendment prefill (owner only, enforced in controller)
    Route::middleware('permission:business.manage_own')
        ->get('businesses/{business}/prefill', [BusinessController::class, 'prefill']);

    // Applications — list/show/timeline (owner or view_all, enforced in controller)
    Route::get('applications', [ApplicationController::class, 'index']);
    Route::get('applications/{application}', [ApplicationController::class, 'show']);
    Route::get('applications/{application}/timeline', [ApplicationController::class, 'timeline']);

    // Per-office application forms (UI prototype Parts 4-7)
    Route::get('applications/{application}/office-forms', [OfficeFormController::class, 'index']);
    // Write allowed for the owner (answers) OR a reviewing officer (issuance
    // dates only) — which keys each may set is enforced in the controller.
    Route::put('applications/{application}/office-forms/{permitTypeCode}', [OfficeFormController::class, 'upsert']);

    // Application create/edit + owner state changes (application.create)
    Route::middleware('permission:application.create')->group(function () {
        Route::post('applications', [ApplicationController::class, 'store']);
        Route::put('applications/{application}', [ApplicationController::class, 'update']);
        Route::post('applications/{application}/submit', [ApplicationController::class, 'submit']);
        Route::post('applications/{application}/resubmit', [ApplicationController::class, 'resubmit']);
        Route::post('applications/{application}/cancel', [ApplicationController::class, 'cancel']);
        // Which permit a renewal/amendment is for (checklist item 50).
        Route::get('applications/{application}/prior-permit', [PriorPermitController::class, 'show']);
        Route::put('applications/{application}/prior-permit', [PriorPermitController::class, 'update']);
    });

    // Documents
    Route::middleware('permission:document.upload_own')->group(function () {
        Route::post('applications/{application}/documents', [DocumentController::class, 'store']);
        // Take an attachment back off a draft (owner only, checked in controller).
        Route::delete('applications/{application}/documents/{document}', [ApplicationController::class, 'destroyDocument']);
    });
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);

    // Messaging (per-application thread; participant check in controller)
    Route::middleware('permission:message.participate')->group(function () {
        // Inbox for the dedicated Messages page: one row per conversation.
        Route::get('message-threads', [MessageController::class, 'threads']);
        Route::get('applications/{application}/messages', [MessageController::class, 'index']);
        Route::post('applications/{application}/messages', [MessageController::class, 'store']);
        Route::get('message-attachments/{attachment}/download', [MessageController::class, 'downloadAttachment']);
    });

    // Officer requests ("Other Requirements")
    Route::get('requests', [OfficerRequestController::class, 'index']);
    Route::middleware('permission:request.create')->group(function () {
        Route::post('applications/{application}/requests', [OfficerRequestController::class, 'store']);
        Route::post('requests/{officerRequest}/close', [OfficerRequestController::class, 'close']);
    });
    Route::middleware('permission:request.respond')
        ->post('requests/{officerRequest}/respond', [OfficerRequestController::class, 'respond']);

    // Payments (owner: payment.make)
    Route::middleware('permission:payment.make')->group(function () {
        Route::get('applications/{application}/fee', [PaymentController::class, 'fee']);
        Route::post('applications/{application}/pay', [PaymentController::class, 'pay']);
        Route::get('payments', [PaymentController::class, 'index']);
    });
    // Receipt PDF (owner-of or officer, enforced in controller)
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);
    // Fee adjustment (officer: fee.adjust)
    Route::middleware('permission:fee.adjust')
        ->post('applications/{application}/fee/adjust', [PaymentController::class, 'adjustFee']);

    // Officer queues + review (application.review)
    Route::middleware('permission:application.review')->group(function () {
        Route::get('assignments', [AssignmentController::class, 'index']);
        Route::get('assignments/{assignment}', [AssignmentController::class, 'show']);
        Route::post('assignments/{assignment}/approve', [AssignmentController::class, 'approve']);
        Route::post('assignments/{assignment}/return', [AssignmentController::class, 'return']);
        Route::post('assignments/{assignment}/checks', [AssignmentController::class, 'checks']);
    });
    /*
     * Rejecting the whole application is not a per-office power. Each office
     * reviews its own clearance and returns its own assignment; ending the
     * application belongs to the issuing office (BPLO) and the super admin.
     * Otherwise a market administrator could terminate a business permit that
     * every other office had already cleared.
     */
    Route::middleware('permission:application.reject')
        ->post('applications/{application}/reject', [ApplicationController::class, 'reject']);
    // OIC: (re)assign an officer to an assignment (oic.assign)
    Route::middleware('permission:oic.assign')
        ->post('assignments/{assignment}/assign', [AssignmentController::class, 'assign']);

    // Inspections (inspection.manage)
    Route::middleware('permission:inspection.manage')->group(function () {
        Route::get('inspections', [InspectionController::class, 'index']);
        Route::get('inspections/{inspection}', [InspectionController::class, 'show']);
        Route::post('inspections/{inspection}/conduct', [InspectionController::class, 'conduct']);
        Route::post('inspections/{inspection}/reschedule', [InspectionController::class, 'reschedule']);
    });

    // Permits — list/show (owner or permit.view_all, enforced in controller)
    Route::get('permits', [PermitController::class, 'index']);
    Route::get('permits/{permit}', [PermitController::class, 'show']);
    // Permit certificate PDF (owner-of or permit.view_all, enforced in controller)
    Route::get('permits/{permit}/pdf', [PermitController::class, 'pdf']);

    // Chatbot (rule-based assistant; self-scoped, one conversation per user)
    Route::get('chatbot/messages', [ChatbotController::class, 'index']);
    Route::post('chatbot/messages', [ChatbotController::class, 'store']);

    // Notifications (self-scoped)
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);

    // Analytics (analytics.view)
    Route::middleware('permission:analytics.view')->group(function () {
        Route::get('analytics/summary', [AnalyticsController::class, 'summary']);
        Route::get('analytics/export', [AnalyticsController::class, 'export']);
        /*
         * Features 6/7 moved out of the standalone r/ project and into the site.
         * They stay on analytics.view (super admin only) because they aggregate
         * every office's assignments — an office reviewer reading these would
         * see round the scoping in ApplicationVisibility.
         */
        /*
         * The Analytics Dashboard (spec §1). Same permission and the same reason:
         * these panels count every office's filings, decisions, inspections and
         * permits, and the barangay and line-of-business rankings amount to a
         * register-wide summary.
         */
        Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('analytics/dashboard/report', [AnalyticsController::class, 'dashboardReport']);
        Route::get('analytics/processing-time', [AnalyticsController::class, 'processingTime']);
        Route::get('analytics/processing-time/report', [AnalyticsController::class, 'processingTimeReport']);
        Route::get('analytics/business-growth', [AnalyticsController::class, 'businessGrowth']);
        Route::get('analytics/business-growth/report', [AnalyticsController::class, 'businessGrowthReport']);
        /*
         * Renewal Risk reads every business's permits, filings, findings and
         * payments to rank them, so it belongs on the same super-admin
         * permission as the rest — a barangay-level watchlist of who is about to
         * fall out of compliance is not an office reviewer's business.
         */
        Route::get('analytics/renewal-risk', [AnalyticsController::class, 'renewalRisk']);
        Route::get('analytics/renewal-risk/report', [AnalyticsController::class, 'renewalRiskReport']);
        /*
         * No staffing-simulation route. App\Support\Des is a complete, tested
         * port of r/R/des.R, but docs/r-integration-spec.md puts the discrete-
         * event simulation out of scope for the delivered flow: the client's
         * paper has six features and DES is not one of them. The port stays on
         * disk with its unit tests; wiring it up is a two-line change here plus
         * the controller if the feature is ever brought back.
         */
    });

    // Admin — user management (user.manage) + activation toggle (owner.manage_status)
    Route::prefix('admin')->group(function () {
        Route::middleware('permission:user.manage')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
        });
        Route::middleware('permission:owner.manage_status')->group(function () {
            Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive']);
            Route::get('businesses', [BusinessStatusController::class, 'index']);
            Route::post('businesses/{business}/status', [BusinessStatusController::class, 'updateStatus']);
        });
        Route::middleware('permission:audit.view')
            ->get('audit-logs', [AuditLogController::class, 'index']);
    });
});
