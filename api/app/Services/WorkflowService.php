<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\PermitStatus;
use App\Exceptions\IllegalTransitionException;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\ApplicationStatusHistory;
use App\Models\FeeAssessment;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\Audit;
use App\Support\Numbering;
use App\Support\Ra11032;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The permit-lifecycle state machine (docs/application-flow-2026-09.md).
 *
 * Every transition goes through here so status history, assignments, fees,
 * inspections, permits and notifications stay consistent. Controllers stay thin.
 *
 * ── The shape, because it changed completely on 6 September 2026 ────────────
 *
 * There are TWO machines and this service drives both. The application's status
 * tracks the filing as a whole; each requested permit carries its own status on
 * the `application_permit_types` pivot. The old service had one, and the flow
 * the client verified against the counter procedure cannot be expressed in one:
 * BPLO reads the form before any money is asked for, the other five permits are
 * then worked independently and released as each finishes, and BPLO signs the
 * whole thing off at the end.
 *
 *   submit            B    draft → for_approval, one bill for everything
 *   approveMainForm   BPLO for_approval → pending_payment
 *   onPaymentCompleted B   pending_payment → awaiting_other_permits
 *   startClearance    B    one permit: not_started → for_approval
 *   approveClearance  OP   one permit: for_approval → for_inspection
 *   scheduleClearanceInspection OP   picks the date
 *   recordInspection  OP   pass → that permit approved AND ISSUED, now
 *   refreshReadiness  S    all five in? → for_final_approval
 *   approveOverall    BPLO → approved, business permit issued
 *
 * What is NOT here any more: `routeToDepartments` (offices are routed one at a
 * time, as the applicant reaches them), `approveAssignment` (an office approves
 * a PERMIT now, not an undifferentiated assignment) and `adjustFee` (the client:
 * the fee is system-computed and BPLO cannot change it).
 */
class WorkflowService
{
    public function __construct(private NotificationService $notify) {}

    // ── writers ─────────────────────────────────────────────────────────────

    /**
     * Record an application status transition + history row + notification.
     *
     * The legality check is the last line of defence and is meant to be
     * unreachable: every caller above should already know whether the move it
     * is about to ask for makes sense. It is here anyway because this is the
     * ONLY write path for `applications.status` — every other guard in this
     * service protects one route, and a new route added next year gets this one
     * for free. `ApplicationStatus::allowedNext()` carries the reasoning for
     * each edge; do not restate it at a call site.
     *
     * A null `$from` is allowed through: a filing with no status yet has no
     * transition to be illegal, and refusing it would break the row's first
     * move rather than protect anything.
     */
    public function transition(Application $app, ApplicationStatus $to, ?string $note = null): void
    {
        $from = $app->status;
        if ($from === $to) {
            return;
        }
        if ($from !== null && ! $from->canTransitionTo($to)) {
            throw IllegalTransitionException::refuse($from, $to);
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
     * The only writer of `application_permit_types.status`.
     *
     * Same argument as `transition()` and the same shape, kept as a separate
     * method rather than generalised into one: the two machines have different
     * legality tables, different audit events and different notification
     * meanings, and a single polymorphic writer would have to branch on all
     * three anyway while making both harder to read.
     *
     * No history TABLE for the pivot. `application_status_history` is keyed to
     * the application and the audit log already records every move with the
     * permit type on it, so a second history table would be a third place to
     * keep in step for a timeline nothing renders yet. If the applicant ever
     * needs a per-permit timeline, that is the moment to add one — not now,
     * on the guess that they might.
     */
    public function transitionClearance(
        ApplicationPermitType $row,
        ClearanceStatus $to,
        ?string $note = null,
    ): void {
        $from = $row->status;
        if ($from === $to) {
            return;
        }
        if ($from !== null && ! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => [
                    'A '.($from?->label() ?? 'new').' permit cannot become '.$to->label().'.',
                ],
            ]);
        }

        $row->update(['status' => $to]);
        Audit::log('clearance.status_changed', $row, [
            'application_id' => $row->application_id,
            'permit_type_id' => $row->permit_type_id,
            'from' => $from?->value,
            'to' => $to->value,
            'note' => $note,
        ]);
    }

    // ── B: submission ───────────────────────────────────────────────────────

    /**
     * Attach the permits this filing must obtain.
     *
     * Runs ONCE, at submission, and never again. That is what makes the bill
     * possible: rule 4 of the spec is one Tax Order of Payment covering
     * everything, raised here, so the permit set has to be final at this moment.
     * Re-deriving it later would either invalidate a bill the applicant has
     * already paid or quietly bill them a second time.
     *
     * It is also why an LGU adding a sixth required clearance next year does not
     * retroactively block filings already in flight — they keep the set they
     * were submitted with. Deliberate: a requirement introduced after someone
     * paid is not one they can be held to.
     *
     * `syncWithoutDetaching` rather than `sync`: the applicant may have opted
     * into Market Clearance during the wizard, and a plain sync would drop it
     * on the floor along with anything a renewal carried over.
     */
    public function attachRequiredPermitTypes(Application $app): void
    {
        $codes = array_merge(
            [PermitType::OUTCOME_CODE],
            PermitType::REQUIRED_CLEARANCE_CODES,
        );

        $ids = PermitType::whereIn('code', $codes)->pluck('id');
        $app->permitTypes()->syncWithoutDetaching(
            $ids->mapWithKeys(fn ($id) => [$id => ['status' => ClearanceStatus::NotStarted->value]])->all()
        );
    }

    /**
     * The Tax Order of Payment, from the seeded revenue-code rules (A10-2016).
     *
     * Called ONCE now, at submission, over every permit the filing will need —
     * the business permit and all five required clearances, plus Market if the
     * applicant opted in. The old service called it twice (once at submit, once
     * per clearance applied for) because clearances were chosen after payment
     * and accrued a balance. There is no balance any more: the client's verified
     * flow pays for everything in one go, before the other permits open.
     *
     * The charge does NOT depend on whether the applicant will apply or upload.
     * Rule 4: an uploaded permit is inspected like any other, and the fee covers
     * the inspection. Making it conditional would also be unknowable here —
     * the applicant does not choose until after they have paid.
     *
     * `updateOrCreate` on `application_id` keeps one assessment row per filing,
     * rewritten in place, so `total_assessed` is the single answer to "what does
     * this filing cost". A second row would leave the receipt, the fee panel and
     * the revenue analytics with two.
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

    /**
     * B: answer and submit the whole application form. draft → for_approval.
     *
     * No fee is payable yet and that is the reversal at the heart of this
     * change. The old flow billed on submission and reviewed after payment, so
     * an applicant paid before anybody had read what they filed; BPLO now reads
     * the form first and their approval is what raises the bill for payment.
     * The assessment is still COMPUTED here, because the applicant is entitled
     * to see what it will cost before they wait — it simply is not due.
     *
     * BPLO is routed here and alone. The other five offices are routed one at a
     * time, when the applicant starts that permit after paying; routing them now
     * would put five filings in five queues that nobody can act on, and start
     * five service-time clocks against work that has not been handed over.
     */
    public function submit(Application $app): Application
    {
        return DB::transaction(function () use ($app) {
            if (! $app->tracking_id) {
                $app->update(['tracking_id' => Numbering::trackingId()]);
            }

            /*
             * The tier decides the deadline. `complexity_set_by_user_id` stays
             * null: null means "classified automatically", which is what this
             * is. `Ra11032::tierFor()` is our rule, not the LGU's published one
             * (open question A10), and BPLO is required to confirm or change it
             * before they can approve — see requireProcessingCategory().
             */
            $submittedAt = now();
            $tier = Ra11032::tierFor($app);
            $app->update([
                'submitted_at' => $submittedAt,
                'complexity' => $tier,
                'deadline_at' => Ra11032::deadlineFor($submittedAt, $tier),
            ]);

            $this->attachRequiredPermitTypes($app);
            $this->assessFees($app);

            $this->transition($app, ApplicationStatus::ForApproval, 'Submitted. Waiting for BPLO to review the form.');
            $this->routeTo($app, $this->bploDepartmentId());

            return $app->fresh();
        });
    }

    // ── BPLO: the first approval ────────────────────────────────────────────

    /**
     * BPLO approves the main form. for_approval → pending_payment.
     *
     * The first of BPLO's two acts. It says the form is fit to be paid for, not
     * that the application is granted — the grant is `approveOverall()`, five
     * approved permits later.
     *
     * The fee is NOT recomputed and BPLO cannot adjust it (client, 6 September
     * 2026: system-computed only). It was assessed at submission from the
     * revenue-code rules and the applicant has been looking at that figure ever
     * since; changing it at the moment it becomes payable would move the number
     * under someone who had already decided to pay it.
     */
    public function approveMainForm(Application $app, ?string $remarks = null): void
    {
        if ($app->status !== ApplicationStatus::ForApproval) {
            throw ValidationException::withMessages([
                'status' => ['Only an application that is For Approval can be approved by BPLO. This one is '.($app->status?->label() ?? 'in no state').'.'],
            ]);
        }

        $this->requireProcessingCategory($app);

        DB::transaction(function () use ($app, $remarks) {
            $this->completeAssignment($app, $this->bploDepartmentId(), $remarks);

            $row = $this->pivotFor($app, PermitType::OUTCOME_CODE);
            if ($row !== null && $row->status === ClearanceStatus::NotStarted) {
                $this->transitionClearance($row, ClearanceStatus::ForApproval, 'BPLO accepted the form.');
            }

            $this->transition(
                $app,
                ApplicationStatus::PendingPayment,
                'BPLO approved the application form. The Tax Order of Payment is ready.',
            );
        });
    }

    /** BPLO returns the main form for revision. for_approval → returned. */
    public function returnMainForm(Application $app, string $remarks): void
    {
        DB::transaction(function () use ($app, $remarks) {
            $app->assignments()
                ->where('department_id', $this->bploDepartmentId())
                ->update(['status' => AssignmentStatus::Returned->value, 'remarks' => $remarks]);
            $this->transition($app, ApplicationStatus::Returned, $remarks);
        });
    }

    /** B: resubmit a returned form. returned → for_approval. */
    public function resubmit(Application $app): void
    {
        DB::transaction(function () use ($app) {
            $app->assignments()
                ->where('status', AssignmentStatus::Returned->value)
                ->update(['status' => AssignmentStatus::Pending->value, 'remarks' => null]);
            $this->transition($app, ApplicationStatus::ForApproval, 'Applicant resubmitted revisions.');
        });
    }

    /** Terminal rejection of the whole filing. Reachable from any live status. */
    public function rejectApplication(Application $app, string $reason): void
    {
        $app->update(['rejection_reason' => $reason, 'decided_at' => now()]);
        $this->transition($app, ApplicationStatus::Rejected, $reason);
        $this->notify->applicationRejected($app, $reason);
    }

    // ── B: payment ──────────────────────────────────────────────────────────

    /**
     * The payment cleared. pending_payment → awaiting_other_permits.
     *
     * One payment, covering everything, and this is the only moment it happens
     * — so unlike the old service there is no second branch here for a balance
     * raised later. If a payment arrives on a filing that is not awaiting one,
     * it is a duplicate or a retry against an already-settled bill and moving
     * the filing on would be wrong; it is ignored rather than refused, because
     * the payment row itself is real and worth keeping.
     *
     * This is also what opens the other permits: `ClearanceService::isUnlocked`
     * asks `status->isPaid()`, which starts answering true here.
     */
    public function onPaymentCompleted(Payment $payment): void
    {
        $app = $payment->application;

        if ($app->status !== ApplicationStatus::PendingPayment) {
            return;
        }

        $this->transition(
            $app,
            ApplicationStatus::AwaitingOtherPermits,
            'Payment received. You can now apply for the other permits.',
        );
    }

    // ── B: one other permit ─────────────────────────────────────────────────

    /**
     * B: apply for, or upload, ONE other permit. not_started → for_approval.
     *
     * `$mode` is how the applicant satisfied it — they filled the office's form
     * (`apply`) or handed in the permit they already hold (`upload`). The office
     * needs the difference because an upload has no form to read, only an image.
     * It changes nothing else: both are reviewed, both are inspected, and both
     * were charged for at submission.
     *
     * Routing happens here rather than at payment, one office at a time. That is
     * what makes `assigned_at` an honest start for the office's measured service
     * time — routing all five when the money landed would charge every office
     * for the days the applicant spent filling in the others' forms.
     */
    public function startClearance(
        Application $app,
        PermitType $type,
        string $mode,
    ): ApplicationPermitType {
        if (! $app->status?->isPaid()) {
            throw ValidationException::withMessages([
                'status' => ['The other permits open once this application is paid.'],
            ]);
        }

        if (! in_array($mode, [ApplicationPermitType::MODE_APPLY, ApplicationPermitType::MODE_UPLOAD], true)) {
            throw ValidationException::withMessages([
                'mode' => ['A permit is either applied for or handed in as a copy you already hold.'],
            ]);
        }

        return DB::transaction(function () use ($app, $type, $mode) {
            $row = $this->pivotFor($app, $type->code);
            if ($row === null) {
                $app->permitTypes()->attach($type->id, ['status' => ClearanceStatus::NotStarted->value]);
                $row = $this->pivotFor($app, $type->code);
            }

            $row->update(['mode' => $mode, 'submitted_at' => now(), 'rejection_reason' => null]);
            $this->transitionClearance(
                $row,
                ClearanceStatus::ForApproval,
                $mode === ApplicationPermitType::MODE_UPLOAD
                    ? 'Applicant handed in a permit they already hold.'
                    : 'Applicant completed the office form.',
            );

            if ($type->issuing_department_id !== null) {
                $this->routeTo($app, $type->issuing_department_id);
            }

            $this->refreshReadiness($app);

            return $row->fresh();
        });
    }

    // ── OP: reviewing one other permit ──────────────────────────────────────

    /**
     * OP approves its permit's paperwork. for_approval → for_inspection.
     *
     * Approving the paperwork is not granting the permit. Every one of the five
     * required permits is inspected — the LGU looks at the premises, not the
     * file — so this books nothing and grants nothing; it moves the permit into
     * the stage where the office picks a date.
     *
     * An office whose permit type does not require an inspection skips straight
     * to approved and the permit is issued here. Nothing seeded is in that
     * position today (BUSINESS is the only `requires_inspection = false` type
     * and it does not come through this path), and the branch exists so that an
     * LGU marking a future clearance desk-only does not get a permit stuck
     * waiting for a visit nobody performs.
     */
    public function approveClearance(ApplicationPermitType $row, ?string $remarks = null): void
    {
        $app = $row->application;
        $type = $row->permitType;

        if ($app->status?->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => ['This application has been decided. Its permits can no longer be acted on.'],
            ]);
        }

        DB::transaction(function () use ($row, $app, $type, $remarks) {
            $row->update(['remarks' => $remarks]);

            if (! $type->requires_inspection) {
                $this->transitionClearance($row, ClearanceStatus::ForInspection);
                $this->grantClearance($row, 'Approved. No inspection is required for this permit.');

                return;
            }

            $this->transitionClearance(
                $row,
                ClearanceStatus::ForInspection,
                ($type->department?->name ?? 'The office').' accepted the paperwork. A site inspection will be scheduled.',
            );
            $this->completeAssignment($app, $type->issuing_department_id, $remarks);
            $this->notify->applicationStatus(
                $app,
                $app->status,
                ($type->department?->name ?? 'An office').' approved your '.$type->name.'. A site inspection will be scheduled.',
            );
        });
    }

    /** OP returns one permit for revision. for_approval → returned. */
    public function returnClearance(ApplicationPermitType $row, string $remarks): void
    {
        DB::transaction(function () use ($row, $remarks) {
            $row->update(['remarks' => $remarks]);
            $this->transitionClearance($row, ClearanceStatus::Returned, $remarks);
            $this->notify->applicationStatus(
                $row->application,
                $row->application->status,
                $row->permitType->name.' was returned for revision: '.$remarks,
            );
        });
    }

    /**
     * OP rejects one permit. It does NOT kill the application.
     *
     * The client's rule, verified 6 September 2026: "only that permit dies". The
     * application stays alive and simply cannot reach `for_final_approval` until
     * the applicant re-files that one permit — `ClearanceStatus::Rejected` may
     * transition back to `NotStarted` for exactly that.
     *
     * `refreshReadiness()` is called because this can happen AFTER the filing
     * already reached `for_final_approval`: five permits approved, BPLO has not
     * pressed the button yet, and an office reverses one. Without the recheck
     * BPLO would be holding an Approve over an application that no longer
     * qualifies.
     */
    public function rejectClearance(ApplicationPermitType $row, string $reason): void
    {
        DB::transaction(function () use ($row, $reason) {
            $row->update(['rejection_reason' => $reason, 'decided_at' => now()]);
            $this->transitionClearance($row, ClearanceStatus::Rejected, $reason);
            $this->notify->applicationStatus(
                $row->application,
                $row->application->status,
                $row->permitType->name.' was not approved: '.$reason.' You can file for it again.',
            );
            $this->refreshReadiness($row->application->fresh());
        });
    }

    /** B: re-file a rejected permit. rejected → not_started. */
    public function refileClearance(ApplicationPermitType $row): void
    {
        $this->transitionClearance($row, ClearanceStatus::NotStarted, 'Applicant is filing for this permit again.');
        $row->update(['mode' => null, 'submitted_at' => null, 'decided_at' => null, 'rejection_reason' => null]);
    }

    // ── OP: the inspection ──────────────────────────────────────────────────

    /**
     * OP picks the inspection date. The client's step, and it is a CHOICE now.
     *
     * The old service booked visits automatically, two working days out, to the
     * least-loaded inspector, the instant an office approved. That is gone: the
     * verified procedure is "Select Inspection Date and Approve Inspection", so
     * the office says when. An automatic date is a promise made to the applicant
     * by a scheduler that does not know whether anyone is free.
     *
     * The least-loaded inspector is still assigned, because somebody has to be
     * named on the visit and the office has not been asked to pick one.
     *
     * Refuses a second CURRENT visit for the same office. A failed visit is kept
     * forever (see recordInspection), and `currentPerDepartment()` is what stops
     * that kept row from counting as an open booking.
     */
    public function scheduleClearanceInspection(ApplicationPermitType $row, mixed $scheduledAt): Inspection
    {
        if ($row->status !== ClearanceStatus::ForInspection) {
            throw ValidationException::withMessages([
                'status' => ['An inspection can only be scheduled once this permit’s paperwork is approved.'],
            ]);
        }

        $app = $row->application;
        $departmentId = $row->permitType->issuing_department_id;

        $alreadyOpen = $app->inspections()
            ->currentPerDepartment()
            ->where('department_id', $departmentId)
            ->whereIn('status', [InspectionStatus::Scheduled->value, InspectionStatus::InProgress->value])
            ->exists();
        if ($alreadyOpen) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['This office already has an inspection booked on this application.'],
            ]);
        }

        return DB::transaction(function () use ($app, $departmentId, $scheduledAt, $row) {
            $visit = $this->openInspection($app, $departmentId, $scheduledAt);

            Audit::log('inspection.scheduled', $visit, [
                'department_id' => $departmentId,
                'permit_type_id' => $row->permit_type_id,
                'scheduled_at' => (string) $visit->scheduled_at,
            ]);

            $this->notify->applicationStatus(
                $app,
                $app->status,
                $row->permitType->name.' inspection is set for '.$visit->scheduled_at->format('d M Y').'.',
            );

            return $visit;
        });
    }

    /**
     * Record the visit's outcome. A pass grants and ISSUES that permit, now.
     *
     * Rule 7 of the spec, and the client was explicit: "the other 6 permits are
     * automatically released once they are approved by their respective admins;
     * no need to wait for each other to be approved." So there is no
     * whole-filing check on this path at all — the old service's `isFullyCleared`
     * gate is gone, because the thing being released is one permit and its own
     * office has just cleared it.
     *
     * A failure moves nothing and is KEPT. The client asked for a record showing
     * a business failed once and passed later, and overwriting the failure is the
     * one thing that would destroy it. The office books a re-inspection against
     * this row; the permit stays `for_inspection` throughout, which is what it is.
     */
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
            return;
        }

        $row = $this->pivotForDepartment($inspection->application, $inspection->department_id);
        if ($row === null || $row->status !== ClearanceStatus::ForInspection) {
            return;
        }

        $this->grantClearance($row, 'Inspection passed.');
    }

    /**
     * Book a fresh visit for an office whose inspection failed.
     *
     * The failed row is left exactly as it is — not updated, not rescheduled,
     * not cancelled. `currentPerDepartment()` is what stops that kept row from
     * blocking the replacement forever.
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

            $this->notify->applicationStatus(
                $app,
                $app->status,
                'A re-inspection has been scheduled for '.$visit->scheduled_at->format('d M Y').'.',
            );

            return $visit;
        });
    }

    // ── BPLO: the final approval ────────────────────────────────────────────

    /**
     * Has every required permit been approved? Move the filing accordingly.
     *
     * Called from both directions — a permit was approved, a permit was
     * rejected — because the filing has to be able to walk BACK out of
     * `for_final_approval`. Five approved permits put it in BPLO's queue; one
     * office reversing itself must take it out again, or BPLO is holding an
     * Approve over an application that no longer qualifies.
     *
     * "Required" is `PermitType::REQUIRED_CLEARANCE_CODES` intersected with what
     * this filing actually carries, not the constant on its own: a filing
     * submitted before a requirement was added keeps the set it was submitted
     * with (see attachRequiredPermitTypes). Market Clearance is excluded by
     * being absent from that constant, so opting into it never blocks anyone.
     *
     * `load()` rather than `loadMissing()` on purpose: every caller has just
     * written to the very rows being counted, and a relation cached before that
     * write is exactly how this reports readiness one approval too early.
     */
    public function refreshReadiness(Application $app): void
    {
        if (! in_array($app->status, [
            ApplicationStatus::AwaitingOtherPermits,
            ApplicationStatus::ForFinalApproval,
        ], true)) {
            return;
        }

        $app->load('permitTypes');

        $outstanding = $app->permitTypes
            ->filter(fn (PermitType $pt) => $pt->isRequiredClearance())
            ->filter(fn (PermitType $pt) => $pt->pivot->status?->isOutstanding() ?? true);

        $ready = $outstanding->isEmpty();

        if ($ready && $app->status === ApplicationStatus::AwaitingOtherPermits) {
            $this->transition(
                $app,
                ApplicationStatus::ForFinalApproval,
                'Every other permit has been approved. Waiting for BPLO’s final approval.',
            );

            return;
        }

        if (! $ready && $app->status === ApplicationStatus::ForFinalApproval) {
            $this->transition(
                $app,
                ApplicationStatus::AwaitingOtherPermits,
                'A permit is outstanding again, so the application is not ready for final approval.',
            );
        }
    }

    /**
     * BPLO's second act: approve the overall application and issue the
     * business permit.
     *
     * This is the ONLY place an application becomes Approved, and the only place
     * the Mayor's Permit is minted. The five other permits were each issued by
     * their own office as they finished; what is left here is the one BPLO
     * issues on the strength of them.
     *
     * The gate is restated rather than assumed. `refreshReadiness()` is what
     * normally puts a filing into `for_final_approval`, so in ordinary operation
     * the status check alone would do — but minting a legal instrument is not an
     * act that should be able to skip the check by arriving through a different
     * door, and a direct caller is exactly the door that would.
     */
    public function approveOverall(Application $app, ?string $remarks = null): void
    {
        $this->requireProcessingCategory($app);

        $app->load('permitTypes');
        $outstanding = $app->permitTypes
            ->filter(fn (PermitType $pt) => $pt->isRequiredClearance())
            ->filter(fn (PermitType $pt) => $pt->pivot->status?->isOutstanding() ?? true);

        if ($outstanding->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permits' => [
                    'These permits are not approved yet, so the application cannot be approved: '
                    .$outstanding->pluck('name')->join(', ').'.',
                ],
            ]);
        }

        DB::transaction(function () use ($app, $remarks) {
            $this->completeAssignment($app, $this->bploDepartmentId(), $remarks);

            $row = $this->pivotFor($app, PermitType::OUTCOME_CODE);
            if ($row !== null && $row->status !== ClearanceStatus::Approved) {
                $row->update(['decided_at' => now()]);
                $row->forceFill(['status' => ClearanceStatus::Approved])->save();
                $this->issuePermitFor($app, $row->permitType);
            }

            $app->update(['decided_at' => now()]);
            $this->transition($app, ApplicationStatus::Approved, 'All requirements met. Business permit issued.');
            $this->notify->applicationApproved($app);
            $this->notify->permitsIssued($app);
        });
    }

    // ── the office-facing entry points ──────────────────────────────────────

    /**
     * An office pressed Approve on its queue item. Work out what that means.
     *
     * The officer's screen still acts on an ASSIGNMENT — one row per office per
     * filing — because that is what a queue item is and what the boundary in
     * `ApplicationVisibility` is keyed to. What an approval *means* now depends
     * entirely on which office is pressing it, and this is the one place that
     * mapping lives so no controller has to know it.
     *
     *  - **BPLO, on a filing that is For Approval** — the first act. They have
     *    read the form and it is fit to be paid for.
     *  - **BPLO, on a filing that is For Final Approval** — the second act.
     *    Every other permit is in; the business permit is issued.
     *  - **BPLO, anywhere else** — refused. In between those two moments the
     *    filing is with the applicant or with the other offices, and BPLO
     *    pressing Approve would be approving nothing. This is the case the old
     *    single-approval model could not express and is the reason a filing
     *    could be pushed forward by an office that had no say at that stage.
     *  - **Any other office** — they are approving THEIR permit's paperwork,
     *    which sends it to inspection rather than granting it.
     */
    public function approveAssignment(ApplicationAssignment $assignment, ?string $remarks = null): void
    {
        $app = $assignment->application;

        if ($app->status?->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => ['This application has been decided and can no longer be approved.'],
            ]);
        }

        if ($assignment->department_id === $this->bploDepartmentId()) {
            match ($app->status) {
                ApplicationStatus::ForApproval => $this->approveMainForm($app, $remarks),
                ApplicationStatus::ForFinalApproval => $this->approveOverall($app, $remarks),
                default => throw ValidationException::withMessages([
                    'status' => [
                        'There is nothing for BPLO to approve while this application is '
                        .($app->status?->label() ?? 'in no state')
                        .'. BPLO approves the form first, then the whole application once every other permit is approved.',
                    ],
                ]),
            };

            return;
        }

        $row = $this->pivotForDepartment($app, $assignment->department_id);
        if ($row === null) {
            throw ValidationException::withMessages([
                'permit' => ['This office has no permit to approve on this application.'],
            ]);
        }

        $this->approveClearance($row, $remarks);
    }

    /** An office returned its queue item. BPLO returns the form; an OP returns its permit. */
    public function returnAssignment(ApplicationAssignment $assignment, string $remarks): void
    {
        $app = $assignment->application;

        if ($assignment->department_id === $this->bploDepartmentId()) {
            $this->returnMainForm($app, $remarks);

            return;
        }

        $row = $this->pivotForDepartment($app, $assignment->department_id);
        if ($row === null) {
            throw ValidationException::withMessages([
                'permit' => ['This office has no permit to return on this application.'],
            ]);
        }

        $this->returnClearance($row, $remarks);
    }

    // ── shared internals ────────────────────────────────────────────────────

    /**
     * Grant one other permit: mark it approved, mint it, recheck the filing.
     *
     * The single place a non-BPLO permit becomes real, reached from a passed
     * inspection and from the desk-only branch of `approveClearance()`. Kept as
     * one method so that "approved" and "issued" cannot drift apart — a permit
     * marked approved with nothing minted is a certificate the applicant can see
     * and not download.
     */
    private function grantClearance(ApplicationPermitType $row, string $note): void
    {
        $app = $row->application;

        $row->update(['decided_at' => now()]);
        $this->transitionClearance($row, ClearanceStatus::Approved, $note);
        $this->issuePermitFor($app, $row->permitType);

        $this->notify->applicationStatus(
            $app,
            $app->status,
            $row->permitType->name.' has been approved and issued.',
        );

        $this->refreshReadiness($app->fresh());
    }

    /**
     * Mint one permit. The only writer of the `permits` table.
     *
     * `firstOrCreate` on (application, permit type) rather than `create`: a
     * re-inspection conducted after a permit was already issued would otherwise
     * mint a second, numbered, legally real duplicate. The old service had that
     * bug and covered it with a status check that no longer applies now that
     * permits are issued one at a time.
     */
    private function issuePermitFor(Application $app, PermitType $type): Permit
    {
        $validityDays = (int) ($type->validity_days ?: 365);

        return Permit::firstOrCreate(
            ['application_id' => $app->id, 'permit_type_id' => $type->id],
            [
                'permit_number' => Numbering::permitNumber($type->permit_number_prefix),
                'business_id' => $app->business_id,
                'status' => PermitStatus::Active,
                'valid_from' => now()->toDateString(),
                'valid_until' => now()->addDays($validityDays)->toDateString(),
                'issued_at' => now(),
                'issued_by_user_id' => Auth::id(),
            ],
        );
    }

    /**
     * Hand this filing to one office.
     *
     * `firstOrCreate`, so an office already on the filing keeps its existing
     * `assigned_at` — re-routing would reset the clock that
     * ProcessingTimeAnalytics measures service time from, and hand the office a
     * fresh deadline on work it started days ago.
     */
    public function routeTo(Application $app, ?int $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        ApplicationAssignment::firstOrCreate(
            ['application_id' => $app->id, 'department_id' => $departmentId],
            ['status' => AssignmentStatus::Pending->value, 'assigned_at' => now()],
        );
    }

    /** Mark an office's queue item done. Silent when it holds none. */
    private function completeAssignment(Application $app, ?int $departmentId, ?string $remarks): void
    {
        if ($departmentId === null) {
            return;
        }

        $assignment = ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $departmentId)
            ->first();
        if ($assignment === null) {
            return;
        }

        $assignment->update([
            'status' => AssignmentStatus::Completed,
            'remarks' => $remarks,
            'completed_at' => now(),
        ]);
        Audit::log('assignment.approved', $assignment);
    }

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

    private function leastLoadedInspector(int $departmentId): ?int
    {
        return User::where('department_id', $departmentId)
            ->where('is_active', true)
            ->withCount(['inspections' => fn ($q) => $q->whereIn('status', ['scheduled', 'in_progress'])])
            ->orderBy('inspections_count')
            ->value('id');
    }

    /** The pivot row for one permit code on this filing, or null. */
    public function pivotFor(Application $app, string $code): ?ApplicationPermitType
    {
        $type = PermitType::where('code', $code)->first();
        if ($type === null) {
            return null;
        }

        return ApplicationPermitType::where('application_id', $app->id)
            ->where('permit_type_id', $type->id)
            ->first();
    }

    /**
     * The pivot row an office is working on this filing.
     *
     * Assignments and inspections are keyed by DEPARTMENT while permits are
     * keyed by type, so getting from a visit back to the permit it was for means
     * going through the office. Every seeded clearance has its own office, so
     * this is exact today; if an LGU ever gives one office two permit types it
     * becomes ambiguous, and the fix then is to put `permit_type_id` on
     * `inspections` rather than to guess harder here.
     */
    private function pivotForDepartment(Application $app, int $departmentId): ?ApplicationPermitType
    {
        $typeIds = PermitType::where('issuing_department_id', $departmentId)->pluck('id');

        return ApplicationPermitType::where('application_id', $app->id)
            ->whereIn('permit_type_id', $typeIds)
            ->first();
    }

    private function bploDepartmentId(): ?int
    {
        return PermitType::where('code', PermitType::OUTCOME_CODE)->value('issuing_department_id');
    }

    /**
     * A filing may not be approved until somebody has said which tier it is.
     *
     * The client: "the admin must not approve the application unless an
     * Application category is chosen."
     *
     * A GUESS IS NOT A CHOICE. `submit()` seeds a tier from `Ra11032::tierFor()`
     * — which, for a new application with no high-tech line above the capital
     * floor, falls through to `complex` — so the column is never null and a
     * null-check would never once fire. The question is who set it: null
     * `complexity_set_by_user_id` means the system guessed, a user id means an
     * officer put their name to it.
     *
     * BPLO is now the office that answers it, at their FIRST approval, and that
     * is a change: it used to be any of the seven offices, at any point before
     * the last one signed off. BPLO reads every filing and reads it earliest, so
     * asking them is both the soonest the question can be answered and the only
     * way to guarantee it is answered by someone rather than by whoever happened
     * to be last. Restated at `approveOverall()` because that is where permits
     * are minted, and minting must not depend on the earlier gate having run.
     */
    private function requireProcessingCategory(Application $app): void
    {
        if (Ra11032::isTier($app->complexity) && $app->complexity_set_by_user_id !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'complexity' => ['Choose this application’s processing category before approving it. The category shown was assigned automatically from the filing type and the declared capital — nobody has checked it against the Citizen’s Charter. Confirm it or change it under For Office Use Only, then approve.'],
        ]);
    }

    /**
     * An office sets which RA 11032 tier this filing belongs to.
     *
     * RA 11032 fixes the DEADLINES — three working days simple, seven complex,
     * twenty highly technical — and fixes nothing about which filing is which.
     * That classification is the LGU's, published in its Citizen's Charter;
     * Malabon has not given us theirs (open question A10), so until they do the
     * office that is reading the filing says which tier it is.
     *
     * The deadline follows, and is recomputed from the FILING DATE, not from
     * today. RA 11032 counts from the filing; recomputing from `now()` would
     * hand the LGU a fresh three weeks by reclassifying on day nineteen, which
     * is the one behaviour a compliance feature must not have. It cuts both ways
     * and is meant to — reclassifying DOWN to simple on day five can put a
     * filing immediately past its deadline, and that is the true statement.
     *
     * Choosing the tier the system already guessed is still a choice, so an
     * unchanged value still gets stamped while nobody has claimed it. It is only
     * a no-op once an officer's name is already on it.
     */
    public function classify(Application $app, string $tier, User $by): Application
    {
        if (! Ra11032::isTier($tier)) {
            throw ValidationException::withMessages([
                'tier' => ['RA 11032 recognises only simple, complex and highly technical transactions.'],
            ]);
        }

        if ($app->status?->isTerminal()) {
            throw ValidationException::withMessages([
                'tier' => ['This application has been decided. Its processing category can no longer be changed.'],
            ]);
        }

        $from = $app->complexity;
        if ($from === $tier && $app->complexity_set_by_user_id !== null) {
            return $app;
        }

        return DB::transaction(function () use ($app, $tier, $by, $from) {
            $deadlineBefore = $app->deadline_at;
            $deadlineAfter = $app->submitted_at
                ? Ra11032::deadlineFor($app->submitted_at, $tier)
                : null;

            $app->update([
                'complexity' => $tier,
                'complexity_set_by_user_id' => $by->id,
                'complexity_set_at' => now(),
                'deadline_at' => $deadlineAfter,
            ]);

            Audit::log('application.reclassified', $app, [
                'from' => $from,
                'to' => $tier,
                'from_working_days' => $from === null ? null : Ra11032::statutoryWorkingDays($from),
                'to_working_days' => Ra11032::statutoryWorkingDays($tier),
                'deadline_from' => $deadlineBefore?->toISOString(),
                'deadline_to' => $deadlineAfter?->toISOString(),
                'department_id' => $by->department_id,
            ]);

            return $app->fresh();
        });
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
}
