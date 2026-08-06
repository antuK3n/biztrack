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
        $this->afterReviewProgress($assignment->application, $assignment);
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

    /**
     * After an office signs off its review: book THAT office's visit, now.
     *
     * This used to open with `every assignment is completed, or return` and do
     * nothing whatsoever until the last office had finished reading. That was
     * defensible while SANITARY and FSIC were the only inspected clearances —
     * two offices, and the visits were the tail of the process. All six
     * supporting clearances are inspected now (ReferenceSeeder), and the same
     * line then means City Health cannot visit a premises it has already
     * cleared on paper because the Market Office has not opened its form yet.
     * Six offices moving at the pace of the slowest, which is the client's
     * question: "when I approved a sanitary permit, why did it not
     * automatically go to inspection?"
     *
     * So the unit of progress is the OFFICE, not the filing. Approving the
     * sanitary review books CHO's visit; approving the fire review books BFP's;
     * neither waits for the other, and an office with no inspection of its own
     * (BPLO, today the only one) simply completes and blocks nothing.
     *
     * Which office it was is the fact this needed and did not have — hence the
     * assignment argument. Deriving it from "the assignment that changed most
     * recently" would be the same guess with a race in it.
     */
    private function afterReviewProgress(Application $app, ApplicationAssignment $approved): void
    {
        $visit = $this->scheduleInspectionFor($app, $approved->department_id);

        /*
         * GUARD 1 of 2 (the other is in recordInspection). Reviews and visits
         * now finish in any order, so the last review can land after every
         * visit has already passed — that filing is complete and must issue
         * here, not sit waiting for an inspection event that will never come
         * again.
         */
        if ($this->isFullyCleared($app)) {
            $this->approveAndIssue($app);

            return;
        }

        /*
         * Nothing booked and nothing outstanding: an office that does not
         * inspect has approved and other offices are still reading. The filing
         * stays `under_review`, which is what it is.
         */
        if (! $app->inspections()->currentPerDepartment()->exists()) {
            return;
        }

        /*
         * "review complete", not "review approved", and that is not a
         * preference. The word Approved inside a `status_change` body is how the
         * applicant ends up with something that reads like a decision on the
         * filing while four offices are still reading it — EndStateNotifications
         * pins that the approval end state is announced once, by
         * applicationApproved(), and nothing else may sound like it.
         */
        $approved->loadMissing('department');
        $note = $visit !== null
            ? ($approved->department?->name ?? 'The office').' review is complete. Site inspection scheduled for '
                .$visit->scheduled_at->format('d M Y').'.'
            : 'Review complete. The filing is still waiting on a site inspection.';

        if ($app->status === ApplicationStatus::ForInspection) {
            /*
             * Already there, because an earlier office booked first. Going
             * through transition() would correctly refuse to write a
             * for_inspection → for_inspection history row — movement that did
             * not happen, the same reason scheduleReinspection() stays away
             * from it — but it would silently drop the message with it, and the
             * applicant should still be told the second office's visit is
             * booked. So say it directly, through the channel every other
             * movement on the filing uses.
             */
            if ($visit !== null) {
                $this->notify->applicationStatus($app, ApplicationStatus::ForInspection, $note);
            }

            return;
        }

        $this->transition($app, ApplicationStatus::ForInspection, $note);
    }

    /**
     * Is the filing clear to issue? BOTH halves, in one place.
     *
     * Read this as the invariant the parallel change had to buy back. While
     * every review had to finish before a single visit could be booked,
     * reaching `for_inspection` at all IMPLIED the paperwork was done, so
     * recordInspection() could release permits on the visits alone and be
     * right. Booking per office removes that implication: a filing whose only
     * inspecting office finished early would otherwise be handed its permits
     * while another office's review was still open — a Mayor's Permit issued
     * over an unread clearance application, which is the one failure this whole
     * change could cause and the one it must not.
     *
     * `load()` rather than `loadMissing()` on purpose: both callers have just
     * written to the very rows being counted, and a relation cached before that
     * write is exactly how this returns true one approval too early.
     *
     * "Current" visit is `Inspection::scopeCurrentPerDepartment()`, the same
     * predicate the scheduler uses, for the reason written on the scope: a
     * failed visit is kept forever, and a kept failure must not veto the
     * re-inspection that replaced it.
     */
    private function isFullyCleared(Application $app): bool
    {
        $app->load('assignments');

        $everyReviewDone = $app->assignments->every(
            fn ($a) => $a->status === AssignmentStatus::Completed
        );
        if (! $everyReviewDone) {
            return false;
        }

        return $app->inspections()
            ->currentPerDepartment()
            ->get()
            ->every(fn ($i) => $i->status === InspectionStatus::Completed
                && $i->result?->progresses());
    }

    /**
     * Auto-schedule ONE office's visit, two working days out, least-loaded
     * inspector. Returns the booking, or null when there was nothing to book.
     *
     * This was `scheduleInspections($app)`, a loop over every inspecting permit
     * type on the filing, called once when the last review landed. The loop is
     * gone rather than kept alongside this: it was the only shape in which the
     * six offices could be booked in one go, and leaving a second, whole-filing
     * booking path in the service is how the "everyone waits for the slowest
     * office" behaviour would find its way back in. One office, one call, one
     * decision — afterReviewProgress() makes it as each review is approved.
     *
     * Two conditions, and a department that fails either gets nothing:
     *
     * - the office must actually inspect ON THIS FILING. It is not enough that
     *   the office has an assignment; the permit type it issues here has to
     *   carry `requires_inspection`. BPLO is the live case — it issues the
     *   Mayor's Permit on the strength of the six clearances, so a visit of its
     *   own would be one nobody performs and would stall issuance behind it
     *   forever.
     * - the office must not already hold a CURRENT visit, and that word is
     *   `Inspection::scopeCurrentPerDepartment()` — the same predicate
     *   isFullyCleared() uses to decide the filing has cleared, on purpose.
     *   Note what the skip covers: a current visit that was conducted and
     *   FAILED also counts as "has one", so a returned filing coming back
     *   through review never books a replacement visit behind anyone's back. A
     *   re-inspection is a decision an officer takes on a date they choose
     *   (scheduleReinspection below); quietly booking one two weekdays out
     *   would pre-empt that and leave no record of who decided.
     *
     * The lookup is deliberately not `Inspection::firstOrCreate(['application_id',
     * 'department_id'])`, which is what it once was: that encoded ONE inspection
     * per (filing, office), an assumption that stopped being true the moment
     * failed visits started being kept, and with history on the table it matches
     * the OLDEST row for the pair — a visit closed weeks ago — and calls it the
     * office's inspection.
     */
    public function scheduleInspectionFor(Application $app, int $departmentId): ?Inspection
    {
        $app->loadMissing('permitTypes');

        $officeInspects = $app->permitTypes->contains(
            fn ($pt) => $pt->requires_inspection && $pt->issuing_department_id === $departmentId
        );
        if (! $officeInspects) {
            return null;
        }

        $alreadyHasVisit = $app->inspections()
            ->currentPerDepartment()
            ->where('department_id', $departmentId)
            ->exists();
        if ($alreadyHasVisit) {
            return null;
        }

        $visit = $this->openInspection($app, $departmentId, now()->addWeekdays(2));

        // Audited like the hand-scheduled re-inspection below, because it is the
        // same kind of fact: who booked a visit against this filing, and for
        // when. An automatic booking is still somebody's approval.
        Audit::log('inspection.scheduled', $visit, [
            'department_id' => $departmentId,
            'scheduled_at' => (string) $visit->scheduled_at,
        ]);

        return $visit;
    }

    /**
     * Write one booking. The only place a row is added to `inspections`.
     *
     * Both the automatic first round and a hand-scheduled re-inspection come
     * through here so that a re-inspection is the same kind of object as the
     * visit it follows — same status, same least-loaded assignment. A second
     * creation path is how a re-inspection would end up without an inspector,
     * or in a status the officer's queue does not filter for.
     */
    private function openInspection(Application $app, int $departmentId, mixed $scheduledAt): Inspection
    {
        return Inspection::create([
            'application_id' => $app->id,
            'department_id' => $departmentId,
            'status' => InspectionStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
            'inspector_user_id' => $this->leastLoadedInspector($departmentId),
        ]);
    }

    /**
     * Book a fresh visit for an office whose inspection failed.
     *
     * The filing stays `for_inspection` throughout — deliberately, and it is the
     * whole reason this does not go through `transition()`. The applicant is
     * still waiting on a site visit; that is what the status says, and saying it
     * again would write a for_inspection → for_inspection row into the timeline
     * that reads as movement where there is none. Nothing here approves
     * anything: the new visit has to be conducted and passed like any other, and
     * recordInspection() is the only thing that can then release the permits.
     *
     * The failed row is left exactly as it is. It is not updated, not
     * rescheduled, not cancelled — the client asked for a record that shows a
     * business failed once and passed later, and overwriting the failure is the
     * one thing that would destroy it. `currentPerDepartment()` is what stops
     * that kept row from also blocking the filing forever.
     *
     * Preconditions are `Inspection::canBeReinspected()`, enforced by the
     * caller: this codebase guards in controllers (InspectionController::
     * reinspect) and keeps services free of HTTP concerns.
     */
    public function scheduleReinspection(Inspection $failed, mixed $scheduledAt): Inspection
    {
        return DB::transaction(function () use ($failed, $scheduledAt) {
            $app = $failed->application;
            $visit = $this->openInspection($app, $failed->department_id, $scheduledAt);

            Audit::log('inspection.reinspection_scheduled', $visit, [
                'replaces_inspection_id' => $failed->id,
                'department_id' => $failed->department_id,
                'scheduled_at' => (string) $visit->scheduled_at,
            ]);

            /*
             * The applicant is told, through the same channel every other
             * movement on their filing uses. `applicationStatus()` rather than a
             * new method: the fact being reported IS the filing's status — it is
             * for inspection, and here is the date of the visit — and it carries
             * the in-app push plus the mail/SMS fan-out that a status ping
             * already has. Inventing a channel for this one event would give the
             * applicant a message that only appears in one of the three places
             * they are used to hearing from us.
             */
            $this->notify->applicationStatus(
                $app,
                ApplicationStatus::ForInspection,
                'A re-inspection has been scheduled for '.$visit->scheduled_at->format('d M Y').'.',
            );

            return $visit;
        });
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
            /*
             * A failure moves nothing on its own, and must not: the premises did
             * not pass, so no permit is due. What happens next is that an officer
             * schedules a re-inspection against this row — scheduleReinspection()
             * above, reached from the inspection detail screen.
             *
             * This early return used to be the end of the road. The comment here
             * said "department may schedule a re-inspection" and nothing in the
             * codebase could: the filing sat in `for_inspection` with every visit
             * conducted, which is also the state in which the officer's screen
             * hides its Approve and Reject controls, so the filing became
             * unreachable from both ends. Six live filings are in that state.
             */
            return;
        }

        $app = $inspection->application;

        /*
         * GUARD 2 of 2 (the other is in afterReviewProgress).
         *
         * This used to test the visits alone — every CURRENT inspection passed,
         * and issue — and that was sound only because of something that is no
         * longer true: a filing could not reach `for_inspection` at all until
         * every office had signed off, so "the visits have passed" quietly
         * carried "and the paperwork was done". Visits are booked per office as
         * each review is approved now, so the very first office to approve and
         * pass its own inspection would arrive here with every CURRENT visit
         * passing while three other offices had not read a page. Left as it was,
         * that issues the permits.
         *
         * isFullyCleared() is both halves and the only statement of them. The
         * CURRENT-visits-only part of the old test lives on inside it, for the
         * reason it was written: the failed row stays on the filing for good, so
         * asking the whole set would let a kept failure veto the re-inspection
         * that replaced it.
         */
        if ($this->isFullyCleared($app)) {
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
