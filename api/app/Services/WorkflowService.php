<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationStatusHistory;
use App\Models\FeeAssessment;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\User;
use App\Support\Audit;
use App\Support\Numbering;
use App\Support\Ra11032;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The permit-lifecycle state machine (master plan §6.2). Every transition goes
 * through here so status history, assignments, fees, inspections, permits, and
 * notifications stay consistent. Controllers stay thin.
 */
class WorkflowService
{
    public function __construct(private NotificationService $notify) {}

    /** Record a status transition + history row + notification to the applicant. */
    public function transition(Application $app, ApplicationStatus $to, ?string $note = null): void
    {
        $from = $app->status;
        if ($from === $to) {
            return;
        }
        $app->update(['status' => $to]);
        ApplicationStatusHistory::create([
            'application_id' => $app->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by_user_id' => Auth::id(),
            'note' => $note,
        ]);
        Audit::log('application.status_changed', $app, ['from' => $from?->value, 'to' => $to->value]);
        $this->notify->applicationStatus($app, $to, $note);
    }

    /**
     * Tax Order of Payment from the seeded revenue-code rules (A10-2016;
     * see FeeCalculator). Applications without a fee profile — or profiles
     * matching no rules — fall back to the legacy per-permit-type flat fee
     * so pre-existing data keeps working.
     */
    public function assessFees(Application $app): FeeAssessment
    {
        $app->loadMissing('permitTypes', 'business.lines');

        $assessed = app(FeeCalculator::class)->assess($app);
        $items = $assessed['items'];
        $total = $assessed['total'];

        if ($items === []) {
            $lineCount = max(1, $app->business->lines->count());
            $total = 0;
            foreach ($app->permitTypes as $pt) {
                $amount = (float) $pt->base_fee + ((float) $pt->per_line_surcharge * $lineCount);
                $items[] = ['label' => $pt->name.' fee (flat schedule)', 'amount' => round($amount, 2)];
                $total += $amount;
            }
            $total = round($total, 2);
        }

        return FeeAssessment::updateOrCreate(
            ['application_id' => $app->id],
            ['line_items' => $items, 'total_amount' => $total]
        );
    }

    /** draft → submitted → pending_payment (auto fee assessment). */
    public function submit(Application $app): Application
    {
        return DB::transaction(function () use ($app) {
            if (! $app->tracking_id) {
                $app->update(['tracking_id' => Numbering::trackingId()]);
            }
            /*
             * The tier decides the deadline. This was a flat ten working days for
             * every filing, under a comment claiming RA 11032 — a figure the
             * statute does not contain and more than three times what a simple
             * transaction is allowed. Complexity was never set here either, so the
             * tier panel structurally excluded every filing made through the real
             * wizard.
             */
            $tier = Ra11032::tierFor($app);
            $submittedAt = now();

            $app->update([
                'submitted_at' => $submittedAt,
                'complexity' => $tier,
                'deadline_at' => Ra11032::deadlineFor($submittedAt, $tier),
            ]);
            $this->transition($app, ApplicationStatus::Submitted);
            $this->assessFees($app);
            $this->transition($app, ApplicationStatus::PendingPayment, 'Fee assessment ready. Awaiting payment.');

            return $app->fresh();
        });
    }

    /**
     * Payment completed → under_review → route to owning departments.
     *
     * One payment, once, and nothing after it. There used to be a second
     * branch here: a later payment meant a clearance balance being settled, and
     * it called `releaseIfSettled()` to retry an issuance the balance gate in
     * approveAndIssue() had refused. Both are gone with the accrual — the
     * clearances are chosen before submission now, so the Tax Order of Payment
     * settled here covers the whole filing and no balance can appear behind it.
     * Do not reintroduce either; see docs/clearances-before-payment.md.
     */
    public function onPaymentCompleted(Payment $payment): void
    {
        if ($payment->application->status !== ApplicationStatus::PendingPayment) {
            return;
        }

        $app = $payment->application;

        DB::transaction(function () use ($app) {
            $this->transition($app, ApplicationStatus::UnderReview, 'Payment received. Routed for review.');
            $this->routeToDepartments($app);
        });
    }

    /**
     * One assignment per department that owns a requested permit type.
     *
     * This is where every clearance is routed to its office, because by the
     * time it runs the applicant has chosen them all: the clearance stage is
     * the last step before Review & Submit, and its permit types are on the
     * filing before this is reached. ClearanceService::apply deliberately does
     * NOT route at the moment a card is ticked — `assigned_at` is the start of
     * the office's service-time clock that ProcessingTimeAnalytics,
     * StaffingSimulation and DashboardAnalytics all measure, and starting it
     * inside somebody's unfinished draft would charge the office for the days
     * the applicant spent typing.
     */
    public function routeToDepartments(Application $app): void
    {
        $app->loadMissing('permitTypes.department');
        $deptIds = $app->permitTypes->pluck('issuing_department_id')->unique();
        foreach ($deptIds as $deptId) {
            ApplicationAssignment::firstOrCreate(
                ['application_id' => $app->id, 'department_id' => $deptId],
                ['status' => AssignmentStatus::Pending, 'assigned_at' => now()]
            );
        }
    }

    /*
     * `routeClearance()` and `withdrawClearanceRouting()` used to sit here.
     *
     * They raised and deleted a single office's assignment at the moment a
     * clearance card was ticked or unticked, which was necessary while the
     * stage opened after payment — the filing was already under review, so
     * routeToDepartments had been and gone. It is not necessary now: the
     * clearances are all chosen before submission, so the set of offices is
     * complete by the time routeToDepartments runs, and un-applying leaves
     * nothing behind to delete.
     */

    /** Officer approves their department's review. */
    public function approveAssignment(ApplicationAssignment $assignment, ?string $remarks = null): void
    {
        $assignment->update([
            'status' => AssignmentStatus::Completed,
            'remarks' => $remarks,
            'completed_at' => now(),
        ]);
        Audit::log('assignment.approved', $assignment);
        $this->afterReviewProgress($assignment->application);
    }

    /** Officer returns the application for revision (applicant fixes → under_review). */
    public function returnAssignment(ApplicationAssignment $assignment, string $remarks): void
    {
        $assignment->update(['status' => AssignmentStatus::Returned, 'remarks' => $remarks]);
        Audit::log('assignment.returned', $assignment, ['remarks' => $remarks]);
        $this->transition($assignment->application, ApplicationStatus::Returned, $remarks);
    }

    /** Officer rejects → application-level terminal rejection. */
    public function rejectApplication(Application $app, string $reason): void
    {
        $app->update(['rejection_reason' => $reason, 'decided_at' => now()]);
        $this->transition($app, ApplicationStatus::Rejected, $reason);
        $this->notify->applicationRejected($app, $reason);
    }

    /** Owner resubmits a returned application → back to under_review. */
    public function resubmit(Application $app): void
    {
        DB::transaction(function () use ($app) {
            $app->assignments()
                ->where('status', AssignmentStatus::Returned->value)
                ->update(['status' => AssignmentStatus::Pending->value, 'remarks' => null]);
            $this->transition($app, ApplicationStatus::UnderReview, 'Applicant resubmitted revisions.');
        });
    }

    /** After each approval: all done → inspection (if required) or approval. */
    private function afterReviewProgress(Application $app): void
    {
        $app->loadMissing('assignments', 'permitTypes');
        $allDone = $app->assignments->every(
            fn ($a) => $a->status === AssignmentStatus::Completed
        );
        if (! $allDone) {
            return;
        }

        $needsInspection = $app->permitTypes->contains(fn ($pt) => $pt->requires_inspection);
        if ($needsInspection) {
            $this->scheduleInspections($app);
            $this->transition($app, ApplicationStatus::ForInspection, 'Reviews complete. Inspection scheduled.');
        } else {
            $this->approveAndIssue($app);
        }
    }

    /** Auto-schedule an inspection per inspecting department (least-loaded inspector). */
    public function scheduleInspections(Application $app): void
    {
        $app->loadMissing('permitTypes.department');
        foreach ($app->permitTypes->where('requires_inspection', true) as $pt) {
            Inspection::firstOrCreate(
                ['application_id' => $app->id, 'department_id' => $pt->issuing_department_id],
                [
                    'status' => InspectionStatus::Scheduled,
                    'scheduled_at' => now()->addWeekdays(2),
                    'inspector_user_id' => $this->leastLoadedInspector($pt->issuing_department_id),
                ]
            );
        }
    }

    private function leastLoadedInspector(int $departmentId): ?int
    {
        return User::where('department_id', $departmentId)
            ->where('is_active', true)
            ->withCount(['inspections' => fn ($q) => $q->whereIn('status', ['scheduled', 'in_progress'])])
            ->orderBy('inspections_count')
            ->value('id');
    }

    /** Record an inspection result; when all pass → approve & issue. */
    public function recordInspection(Inspection $inspection, InspectionResult $result, ?string $findings, array $photos = []): void
    {
        $inspection->update([
            'status' => InspectionStatus::Completed,
            'result' => $result,
            'findings' => $findings,
            'photo_paths' => $photos ?: null,
            'conducted_at' => now(),
        ]);
        Audit::log('inspection.recorded', $inspection, ['result' => $result->value]);

        if (! $result->progresses()) {
            return; // failed: department may schedule a re-inspection
        }

        $app = $inspection->application;
        $allPassed = $app->inspections()
            ->get()
            ->every(fn ($i) => $i->status === InspectionStatus::Completed
                && $i->result?->progresses());
        if ($allPassed) {
            $this->approveAndIssue($app);
        }
    }

    /** Officer adjusts the fee assessment (before payment). Audits + notifies. */
    public function adjustFee(Application $app, array $lineItems, float $total, User $by): FeeAssessment
    {
        $fee = FeeAssessment::updateOrCreate(
            ['application_id' => $app->id],
            [
                'line_items' => $lineItems,
                'total_amount' => round($total, 2),
                'adjusted_by_user_id' => $by->id,
            ]
        );
        Audit::log('fee.adjusted', $fee, ['total' => (string) $fee->total_amount]);

        if ($app->status === ApplicationStatus::PendingPayment) {
            $this->notify->feeAdjusted($app);
        }

        return $fee->fresh();
    }

    /** OIC: (re)assign an officer to an assignment. Audits. */
    public function assignOfficer(ApplicationAssignment $assignment, User $officer, ?string $reason = null): void
    {
        $assignment->update(['officer_user_id' => $officer->id]);
        Audit::log('assignment.reassigned', $assignment, [
            'officer_user_id' => $officer->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Terminal: approve and issue one permit per requested permit type.
     *
     * There is no balance check here, and there should not be one.
     *
     * It briefly refused to release anything while money was owed, because a
     * clearance applied for after the first payment accrued onto the same
     * FeeAssessment and something had to make that accrual real. Nothing
     * accrues now: every clearance is chosen before submission, the filing is
     * assessed once, and it cannot reach review at all until that one Tax Order
     * of Payment has cleared. A gate here would only ever fire on an officer
     * adjusting the assessment upward after payment — which is a conversation
     * with the applicant, not a reason to withhold a permit the offices have
     * already approved.
     */
    public function approveAndIssue(Application $app): void
    {
        DB::transaction(function () use ($app) {
            $app->loadMissing('permitTypes');
            foreach ($app->permitTypes as $pt) {
                // paper Table 30: valid_until = valid_from + validity_days.
                $validityDays = (int) ($pt->validity_days ?: 365);
                Permit::create([
                    'permit_number' => Numbering::permitNumber($pt->permit_number_prefix),
                    'application_id' => $app->id,
                    'business_id' => $app->business_id,
                    'permit_type_id' => $pt->id,
                    'status' => PermitStatus::Active,
                    'valid_from' => now()->toDateString(),
                    'valid_until' => now()->addDays($validityDays)->toDateString(),
                    'issued_at' => now(),
                    'issued_by_user_id' => Auth::id(),
                ]);
            }
            $app->update(['decided_at' => now()]);
            $this->transition($app, ApplicationStatus::Approved, 'All requirements met. Permit(s) issued.');
            // The generic status ping is suppressed for the two end states, so
            // approval says so plainly and points at the issued permit.
            $this->notify->applicationApproved($app);
            $this->notify->permitsIssued($app);
        });
    }
}
