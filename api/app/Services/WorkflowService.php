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
use App\Models\PermitType;
use App\Models\User;
use App\Support\Audit;
use App\Support\Numbering;
use App\Support\PermitFees;
use App\Support\Ra11032;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** Payment completed → under_review → route to owning departments. */
    public function onPaymentCompleted(Payment $payment): void
    {
        $app = $payment->application;

        if ($app->status === ApplicationStatus::PendingPayment) {
            DB::transaction(function () use ($app) {
                $this->transition($app, ApplicationStatus::UnderReview, 'Payment received. Routed for review.');
                $this->routeToDepartments($app);
            });

            return;
        }

        // Any later payment is a clearance balance being settled. It may be the
        // only thing still holding the permit back, so the release is retried
        // here — see releaseIfSettled().
        $this->releaseIfSettled($app->fresh());
    }

    /**
     * Issue now if a settled balance was the last thing in the way.
     *
     * The counterpart to the balance gate in approveAndIssue(). Issuance is
     * otherwise only ever attempted at the moment a review or an inspection
     * completes — so a filing whose offices had all signed off, and which was
     * refused release only because a clearance was applied for afterwards,
     * would have had no second attempt: every event that could have retried it
     * had already happened. Paying is then the event, and this is the retry.
     *
     * Deliberately silent when the filing is not ready for any other reason. It
     * re-checks the same two conditions the normal path does rather than
     * trusting that a payment means everything else is done.
     */
    public function releaseIfSettled(?Application $app): void
    {
        if ($app === null || $app->status->isTerminal()) {
            return;
        }
        if (PermitFees::hasOutstandingBalance($app)) {
            return;
        }

        $app->loadMissing('assignments', 'permitTypes', 'inspections');

        // isNotEmpty: a filing with no assignments at all has not been reviewed,
        // and `every` on an empty collection answers true.
        $reviewsDone = $app->assignments->isNotEmpty()
            && $app->assignments->every(fn ($a) => $a->status === AssignmentStatus::Completed);
        if (! $reviewsDone) {
            return;
        }

        if ($app->permitTypes->contains(fn ($pt) => $pt->requires_inspection)) {
            $inspectionsPassed = $app->inspections->isNotEmpty()
                && $app->inspections->every(fn ($i) => $i->status === InspectionStatus::Completed
                    && $i->result?->progresses());
            if (! $inspectionsPassed) {
                return;
            }
        }

        $this->approveAndIssue($app);
    }

    /** One assignment per department that owns a requested permit type. */
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

    /**
     * Route one clearance to its office the moment it is applied for.
     *
     * Spec rule 7: a clearance routes when applied for, not at submission. That
     * is the whole difference from routeToDepartments() above — same table,
     * same firstOrCreate, one department instead of the set — so it is an
     * extension of the existing routing rather than a parallel mechanism. The
     * firstOrCreate matters: an office already reviewing this filing for
     * another reason keeps the row and the history on it.
     */
    public function routeClearance(Application $app, PermitType $type): ?ApplicationAssignment
    {
        if (! $type->issuing_department_id) {
            return null;
        }

        return ApplicationAssignment::firstOrCreate(
            ['application_id' => $app->id, 'department_id' => $type->issuing_department_id],
            ['status' => AssignmentStatus::Pending, 'assigned_at' => now()]
        );
    }

    /**
     * Withdraw the routing when a clearance is un-applied.
     *
     * Only a Pending row, and only when no permit type still on the filing
     * routes to that office. Both conditions are about not destroying work: an
     * assignment an officer has picked up, returned or completed is a record of
     * something that happened, and an office kept on the filing by another
     * clearance still has a queue item to answer. Anything else and the row
     * stays — a stale pending assignment is a smaller wrong than a deleted
     * review.
     */
    public function withdrawClearanceRouting(Application $app, PermitType $type): void
    {
        if (! $type->issuing_department_id) {
            return;
        }

        $app->loadMissing('permitTypes');
        $stillRouted = $app->permitTypes
            ->contains(fn (PermitType $pt) => $pt->issuing_department_id === $type->issuing_department_id);
        if ($stillRouted) {
            return;
        }

        $assignment = $app->assignments()
            ->where('department_id', $type->issuing_department_id)
            ->where('status', AssignmentStatus::Pending->value)
            ->first();

        if ($assignment) {
            Audit::log('assignment.withdrawn', $assignment, ['permit_type' => $type->code]);
            $assignment->delete();
        }

        $app->load('assignments');
    }

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
     * Nothing is released while money is owed (spec rule 6). Each clearance
     * applied for after the first payment re-assesses into the same
     * FeeAssessment, so the balance is what makes that accrual real — without
     * this gate an applicant could apply for all six the moment the business
     * permit cleared and be issued every one of them without paying for any.
     *
     * A ValidationException rather than a bare abort because the caller is an
     * officer pressing Approve: it surfaces as a 422 next to the action, naming
     * the amount, instead of a 500 that says the system broke.
     */
    public function approveAndIssue(Application $app): void
    {
        $balance = PermitFees::balance($app);
        if ($balance['balance_due'] > PermitFees::EPSILON) {
            throw ValidationException::withMessages([
                'balance_due' => [
                    'This permit can’t be released yet: '
                    .PermitFees::peso($balance['balance_due'])
                    .' of the assessed '
                    .PermitFees::peso($balance['total_assessed'])
                    .' is still unpaid. The applicant has to settle the balance for the clearances applied for before any permit is issued.',
                ],
            ]);
        }

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
