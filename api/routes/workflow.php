<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\BusinessStatusController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\ClearanceController;
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

    /*
     * The LGU clearance stage (docs/clearances-after-payment.md).
     *
     * Owner-only throughout, checked in the controller — the read included,
     * because which clearances a business asks for is the applicant's own
     * decision and not something an office needs the chooser to see.
     *
     * On `application.create` rather than `document.upload_own` even for the
     * held upload: all four writes are the same decision about what this filing
     * is asking for, and splitting them across two permissions would let a role
     * hold half a stage. Both permissions sit on business_owner today, so this
     * narrows nothing that exists.
     */
    Route::middleware('permission:application.create')->group(function () {
        Route::get('applications/{application}/clearances', [ClearanceController::class, 'index']);
        Route::post('applications/{application}/clearances/{code}/apply', [ClearanceController::class, 'apply']);
        Route::delete('applications/{application}/clearances/{code}/apply', [ClearanceController::class, 'unapply']);
        Route::post('applications/{application}/clearances/{code}/held', [ClearanceController::class, 'storeHeld']);
        Route::delete('applications/{application}/clearances/{code}/held', [ClearanceController::class, 'destroyHeld']);
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
        /*
         * A question with no filing behind it, addressed to BPLO.
         *
         * No `{user}` means "mine", which is what an applicant always sends —
         * someone who has registered no business has no application id to put
         * in a path, and telling them to "contact the City BPLO" while giving
         * them no way to do it is what this fixes. BPLO names the person whose
         * enquiry it is opening; the office check is in the controller, so the
         * two-segment form is not a way in for anybody else.
         */
        Route::get('general-messages/{user?}', [MessageController::class, 'generalIndex']);
        Route::post('general-messages/{user?}', [MessageController::class, 'generalStore']);
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
        /*
         * The filing's RA 11032 processing category, set by the office reading
         * it. In this group on purpose: `application.review` is what every one
         * of the seven offices holds and no applicant does, which is exactly
         * the client's "all office admins" and no wider. Which office may set
         * it on WHICH filing is AssignmentController::authorizeDepartment's
         * decision, the same as approve and return above.
         */
        Route::post('assignments/{assignment}/classification', [AssignmentController::class, 'classify']);
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
        /*
         * Re-inspection after a failure. On `inspection.manage` with the rest,
         * because it is the same act as scheduling the first visit and the same
         * people do it — the office that failed the premises, and BPLO/admin.
         * It is not a decision on the application, so it does not belong with
         * `application.reject`: nothing here approves, and the filing stays for
         * inspection until somebody conducts the new visit.
         */
        Route::post('inspections/{inspection}/reinspect', [InspectionController::class, 'reinspect']);
    });

    // Permits — list/show (owner or permit.view_all, enforced in controller)
    Route::get('permits', [PermitController::class, 'index']);
    /*
     * The clearances the applicant submitted a copy of rather than applied for.
     * Self-scoped in the controller, so no permission gate here.
     *
     * ABOVE `permits/{permit}`, and it has to stay there: route matching is
     * first-come, so registered after it Laravel would bind "held" as a permit
     * key and answer 404 on a request that is not asking for a permit at all.
     */
    Route::get('permits/held', [PermitController::class, 'held']);
    Route::get('permits/{permit}', [PermitController::class, 'show']);
    // Permit certificate PDF (owner-of or permit.view_all, enforced in controller)
    Route::get('permits/{permit}/pdf', [PermitController::class, 'pdf']);

    // Chatbot (rule-based assistant; self-scoped, one conversation per user)
    Route::get('chatbot/messages', [ChatbotController::class, 'index']);
    Route::post('chatbot/messages', [ChatbotController::class, 'store']);

    // Notifications (self-scoped)
    /*
     * Both nav badges in one call. Deliberately NOT inside the
     * `message.participate` group: the badge is drawn on every screen for every
     * seat, and an officer without that permission still has notifications.
     * The counts are scoped to the reader inside the controller.
     */
    Route::get('unread-summary', [MessageController::class, 'unreadSummary']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);

    // Analytics (analytics.view)
    Route::middleware('permission:analytics.view')->group(function () {
        Route::get('analytics/summary', [AnalyticsController::class, 'summary']);
        Route::get('analytics/export', [AnalyticsController::class, 'export']);
        /*
         * Features 6/7 moved out of the standalone r/ project and into the site.
         * They stay on analytics.view because they aggregate every office's
         * assignments — an office reviewer reading these would see round the
         * scoping in ApplicationVisibility. Checklist #78 added BPLO to that
         * permission; BPLO is the one office role that already holds
         * application.view_any_office, so the boundary is not new to it.
         */
        /*
         * The Analytics Dashboard (spec §1). Same permission and the same reason:
         * these panels count every office's filings, decisions, inspections and
         * permits, and the barangay and line-of-business rankings amount to a
         * register-wide summary.
         */
        Route::get('analytics/dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('analytics/dashboard/report', [AnalyticsController::class, 'dashboardReport']);
        Route::get('analytics/business-growth', [AnalyticsController::class, 'businessGrowth']);
        Route::get('analytics/business-growth/report', [AnalyticsController::class, 'businessGrowthReport']);
        /*
         * Renewal Risk reads every business's permits, filings, findings and
         * payments to rank them, so it belongs on the same permission as the
         * rest — a barangay-level watchlist of who is about to fall out of
         * compliance is not an ordinary office reviewer's business.
         */
        Route::get('analytics/renewal-risk', [AnalyticsController::class, 'renewalRisk']);
        Route::get('analytics/renewal-risk/report', [AnalyticsController::class, 'renewalRiskReport']);
        /*
         * The fitted model shown beside that watchlist. Same permission and the
         * same reader, deliberately: it is the same screen, and a reader trusted
         * with the rule score is the reader who needs to see how far the fitted
         * figure beside it can be trusted.
         */
        Route::get('analytics/renewal-model', [AnalyticsController::class, 'renewalModel']);
        /*
         * The Send Reminder / Immediate Follow-up button on that screen. The
         * only route in this file that sends a message to a citizen on an
         * officer's say-so, which is why three things are true of it:
         *
         *  - **It sits on `analytics.view`, not on a notification permission.**
         *    The authority being exercised is "I have read the watchlist and
         *    this business needs chasing", and the watchlist is what
         *    analytics.view opens. Nobody who cannot see the row should be able
         *    to act on it — and the super admin, which no longer holds this
         *    permission, must not acquire it here by the back door.
         *  - **Keyed on the permit, not the business.** A business commonly
         *    holds three permits expiring on three dates and the watchlist has
         *    a row per permit; the message quotes a permit number and an expiry
         *    date, so a business-keyed route would have to guess which row the
         *    officer was looking at.
         *  - **Throttled.** Not for load — one send is one notification row —
         *    but because the far end is a real person's phone. Twenty a minute
         *    is more follow-ups than an office makes in an hour and still stops
         *    a stuck key becoming a hundred messages. The per-permit-per-day
         *    ledger guard in the controller is the real protection against a
         *    double send; this is the blunt outer one.
         */
        Route::post('analytics/renewal-risk/{permit}/remind', [AnalyticsController::class, 'remindRenewal'])
            ->middleware('throttle:20,1');

        /*
         * Manual refresh, for when waiting for the nightly run will not do — a
         * demo, or an officer who has just filed something and wants the figures
         * to include it.
         *
         * Throttled because one call recomputes the whole register: a year of
         * review history, the full renewal watchlist and a fitted model, over
         * twenty dataset variants and a second or two of query and arithmetic.
         * It used to push all of that to a separate R service over HTTP; the
         * work is now in-process, which removes the network but not the cost.
         * Holding it to a few calls a minute stops a held-down button turning
         * into a self-inflicted load test on the database every page load shares.
         */
        Route::post('analytics/refresh', [AnalyticsController::class, 'refresh'])
            ->middleware('throttle:4,1');
    });

    /*
     * Permit Processing Time Monitoring — spec §6, "(Super Admin)".
     *
     * Its own permission, and the only analytics screen the super admin holds.
     * This one measures the DEPARTMENTS, BPLO among them, so it sits with the
     * office doing the oversight rather than the office being overseen. See the
     * note on the admin role in RbacSeeder.
     */
    Route::middleware('permission:analytics.processing_time')->group(function () {
        Route::get('analytics/processing-time', [AnalyticsController::class, 'processingTime']);
        Route::get('analytics/processing-time/report', [AnalyticsController::class, 'processingTimeReport']);
        /*
         * No staffing-simulation route. App\Support\Des is a complete, tested
         * discrete-event simulation, but it is out of scope for the delivered
         * flow: the client's paper has six features and DES is not one of them.
         * It stays on disk with its unit tests; wiring it up is a two-line
         * change here plus the controller if the feature is ever brought back.
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
