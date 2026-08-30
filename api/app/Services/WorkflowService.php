<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\PermitStatus;
use App\Exceptions\IllegalTransitionException;
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

    /**
     * Record a status transition + history row + notification to the applicant.
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
     * Tax Order of Payment from the seeded revenue-code rules (A10-2016;
     * see FeeCalculator). Applications without a fee profile — or profiles
     * matching no rules — fall back to the legacy per-permit-type flat fee
     * so pre-existing data keeps working.
     *
     * Called at TWO moments now, and it needs to know about neither of them.
     * `submit()` calls it over the permit types the wizard filed — the business
     * permit alone, since the clearance stage is shut until payment — and
     * ClearanceService::apply/unapply call it again over the permit types the
     * filing carries after a clearance was added or withdrawn. Because
     * FeeCalculator gates every rule on the requested permit types, the second
     * assessment differs from the first by exactly that clearance's lines.
     *
     * `updateOrCreate` on `application_id` is what makes the accrual work: one
     * assessment row per filing, rewritten in place, so `total_assessed` minus
     * the payments ledger is the balance. A second row would leave every reader
     * of "what does this filing cost" — the receipt, the fee panel, the
     * balance, the revenue analytics — with two answers.
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
     * draft → submitted → pending_payment (auto fee assessment).
     *
     * The assessment written here covers the BUSINESS PERMIT alone, and that is
     * the ordering rather than a property of this method: the clearance stage
     * is locked until the first payment clears, so a filing arriving here
     * carries the outcome permit and nothing else. Anything a clearance costs
     * joins this same assessment later, when the applicant applies for it
     * (ClearanceService::apply).
     */
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

            /*
             * `complexity_set_by_user_id` is deliberately left null here.
             *
             * Null means "classified automatically", and that is exactly what
             * this is — `tierFor()` is our rule, not the LGU's published one
             * (A10). The review sheet reads this to tell an officer whether
             * they are overriding a guess or filling in a blank, so writing
             * the applicant's id here would claim a human decision that nobody
             * made. `classify()` below is the only writer of that column.
             */
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
     * A reviewing office sets which RA 11032 tier this filing belongs to.
     *
     * ── Why this is settable at all ───────────────────────────────────────────
     *
     * RA 11032 fixes the DEADLINES — three working days simple, seven complex,
     * twenty highly technical — and fixes nothing about which filing is which.
     * That classification is the LGU's, published in its Citizen's Charter.
     * Malabon has not given us theirs (open question A10), so until now every
     * filing was tiered by `Ra11032::tierFor()`, a rule we wrote and nobody at
     * BPLO approved, and the dashboard's compliance rate was measured against
     * it. The client asked for the obvious remedy: let the office that is
     * actually reading the filing say which tier it is.
     *
     * The tiers themselves stay untouchable. `$tier` is checked against
     * `Ra11032::TIERS` here as well as by the controller's validation rule,
     * because this is the one write path for a statutory clock and it should
     * not depend on a caller having remembered to validate.
     *
     * ── The deadline follows, and it is recomputed from the FILING DATE ───────
     *
     * This is the decision worth reading twice. `deadline_at` was computed at
     * submission from the tier at submission, so reclassifying without touching
     * it would leave a filing labelled "Simple · 3 working days" carrying a
     * seven-day deadline — a row that contradicts itself and a compliance
     * figure that is quietly wrong in whichever direction the officer moved.
     *
     * Of the two honest options, the clock is restarted from `submitted_at`,
     * not from today. RA 11032 counts from the filing, not from the moment an
     * office got round to categorising it; recomputing from `now()` would hand
     * the LGU a fresh three weeks by reclassifying on day nineteen, which is
     * the one behaviour a compliance feature must not have. It cuts both ways
     * and is meant to: reclassifying a filing DOWN to simple on day five can
     * put it immediately past its deadline, and that is the true statement —
     * a simple transaction filed five working days ago IS late.
     *
     * A filing with no `submitted_at` has no clock to run, so it keeps a null
     * deadline rather than acquiring one dated from nothing. In practice this
     * cannot be reached from the review sheet (an unsubmitted filing has no
     * assignment), and it is here so that a later caller cannot make it true.
     *
     * ── What may not be reclassified ──────────────────────────────────────────
     *
     * A terminal filing. Approved, rejected and cancelled are decisions with
     * `decided_at` already written and, for approvals, permits already issued;
     * moving the tier under one of those rewrites whether the LGU met its
     * statutory deadline on a case that is closed. Same rule and same reason as
     * `ApplicationStatus::isTerminal()` guarding the transition table.
     *
     * Returns the application whether or not anything changed. Setting the tier
     * it already has is a no-op on purpose — no audit row, no deadline churn —
     * because an officer pressing Save on an unchanged select has not made a
     * decision about a statutory clock.
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

        /*
         * Choosing the tier the system already guessed is still a choice.
         *
         * This used to return early whenever the value was unchanged, which was
         * right while the tier was only a value. It is not right now that the
         * approval gate asks WHO set it: submit() seeds a guess from
         * Ra11032::tierFor() and leaves complexity_set_by_user_id null, so an
         * officer who reads the filing, agrees with the guess and picks the same
         * option would change nothing, never be recorded as having chosen, and
         * stay blocked from approving with no way out but picking a tier they
         * believe is wrong.
         *
         * So an unchanged value still falls through to be stamped when nobody
         * has claimed it yet. It is only genuinely a no-op once an officer's
         * name is already on it.
         */
        $from = $app->complexity;
        if ($from === $tier && $app->complexity_set_by_user_id !== null) {
            return $app;
        }

        $app = DB::transaction(function () use ($app, $tier, $by, $from) {
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

            /*
             * Audited because this moves a statutory deadline, which is the
             * sort of change an LGU has to be able to account for afterwards.
             * Both the tier and both deadlines are recorded: "simple → complex"
             * alone does not tell an auditor how many days the office gained or
             * lost, and that is the number the compliance rate is built from.
             */
            Audit::log('application.reclassified', $app, [
                'from' => $from,
                'to' => $tier,
                'from_working_days' => $from === null ? null : Ra11032::statutoryWorkingDays($from),
                'to_working_days' => Ra11032::statutoryWorkingDays($tier),
                'deadline_from' => $deadlineBefore?->toISOString(),
                'deadline_to' => $deadlineAfter?->toISOString(),
                // Which office, not only which person: the client's request is
                // that every office may do this, so "who" has two halves.
                'department_id' => $by->department_id,
            ]);

            return $app->fresh();
        });

        /*
         * GUARD 3 of 3, and the one that keeps the approval gate from being a
         * trap (the other two are in afterReviewProgress and recordInspection).
         *
         * approveAndIssue() now refuses an uncategorised filing. Every event
         * that can leave a filing "everything done except the category" must
         * therefore re-test whether it is complete once the category arrives,
         * or the filing has no remaining event to release it: its reviews are
         * all in, its visits have all passed, and nothing will call
         * afterReviewProgress or recordInspection on it again. That state is
         * not hypothetical — it is what the pre-gate rows in the register look
         * like, and BIZ-2026-00462 is sitting in it right now.
         *
         * Unreachable for a filing that still owes anybody anything, which is
         * every filing created since the gate: isFullyCleared() is the same
         * both-halves test the other two guards use, so an office that has not
         * read the papers or an inspector who has not visited still blocks it.
         * Categorising is not a shortcut past either.
         */
        if ($this->isFullyCleared($app)) {
            $this->approveAndIssue($app);

            return $app->fresh();
        }

        return $app;
    }

    /**
     * A payment cleared. Which payment it was decides what happens next.
     *
     * TWO branches, because there are two moments money changes hands
     * (docs/clearances-after-payment.md, "one ledger, two moments"):
     *
     *  - the FIRST payment settles the business permit's Tax Order of Payment.
     *    The filing moves into review, its offices are routed, and — the part
     *    that is not visible from here — ClearanceService::isUnlocked starts
     *    answering true, so the six clearances open.
     *  - a LATER payment settles a balance raised by a clearance applied for
     *    after that. Nothing about the filing's status changes: it is already
     *    under review, or for inspection, or sitting with every office signed
     *    off and its permits withheld by the release gate. That last case is
     *    why `releaseIfSettled()` is called — the offices have finished, the
     *    money has now landed, and nothing else in the system will ever look
     *    at this filing again to notice. Without it the applicant pays the
     *    balance and their permit stays unissued forever.
     */
    public function onPaymentCompleted(Payment $payment): void
    {
        $app = $payment->application;

        if ($app->status !== ApplicationStatus::PendingPayment) {
            $this->releaseIfSettled($app);

            return;
        }

        DB::transaction(function () use ($app) {
            $this->transition($app, ApplicationStatus::UnderReview, 'Payment received. Routed for review.');
            $this->routeToDepartments($app);
        });
    }

    /**
     * One assignment per department that owns a requested permit type.
     *
     * Runs once, when the first payment clears, over whatever the filing asks
     * for at that moment — which under this ordering is the business permit
     * alone. Every clearance is applied for afterwards and routes itself
     * (routeClearance below), so this is no longer the only door into the
     * assignments table and must not be written as though it were.
     *
     * `firstOrCreate` rather than `create` for that reason: the two paths can
     * legitimately want the same office. Re-routing an office that already has
     * a row would reset `assigned_at` and hand it a fresh service-time clock on
     * work it started days ago.
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

    /**
     * Route ONE clearance to its office, at the moment it is applied for.
     *
     * Rule 7 of docs/clearances-after-payment.md. The clearance stage opens
     * after payment, so `routeToDepartments` has already been and gone by the
     * time a clearance is chosen; without this the applicant would be billed
     * for a clearance no office was ever told to work.
     *
     * Only on a filing whose first payment has cleared, which is also the only
     * state ClearanceService lets `apply` be reached in. Stated here as well
     * because `assigned_at` is the start of the office's measured service time
     * (ProcessingTimeAnalytics, StaffingSimulation, DashboardAnalytics), and a
     * future caller that routed an unpaid filing would silently charge the
     * office for every day before the money arrived. The gate belongs next to
     * the clock it protects.
     *
     * `firstOrCreate`, so a clearance whose office is already on the filing
     * joins that office's existing queue item rather than resetting its clock.
     */
    public function routeClearance(Application $app, PermitType $type): void
    {
        if ($type->issuing_department_id === null || ! PermitFees::hasClearedPayment($app)) {
            return;
        }

        ApplicationAssignment::firstOrCreate(
            ['application_id' => $app->id, 'department_id' => $type->issuing_department_id],
            ['status' => AssignmentStatus::Pending, 'assigned_at' => now()]
        );
    }

    /**
     * Un-apply: take that office's assignment back off the filing.
     *
     * Two refusals, and both are about not deleting something real:
     *
     *  - another permit type still on the filing issues from the same office.
     *    Assignments are keyed per DEPARTMENT, not per permit type, so deleting
     *    the row would take the other clearance's review with it. Every seeded
     *    clearance has its own office today, so this is defence against a
     *    future LGU's seed rather than a live case — but the failure it
     *    prevents is an office silently losing a live queue item.
     *  - the office has already picked the work up. `officeHasActed` in
     *    ClearanceService refuses the withdrawal upstream for exactly this, so
     *    reaching here with a started assignment means a caller bypassed it;
     *    the remarks an officer has typed are not something a cancel button
     *    gets to erase.
     */
    public function withdrawClearanceRouting(Application $app, PermitType $type): void
    {
        if ($type->issuing_department_id === null) {
            return;
        }

        $app->loadMissing('permitTypes');
        $stillNeeded = $app->permitTypes
            ->contains(fn (PermitType $pt) => $pt->issuing_department_id === $type->issuing_department_id);
        if ($stillNeeded) {
            return;
        }

        ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $type->issuing_department_id)
            ->where('status', AssignmentStatus::Pending->value)
            ->delete();
    }

    /**
     * The balance cleared — release permits the gate was holding, if any.
     *
     * The counterpart to the release gate in approveAndIssue(). The offices
     * finish their reviews on their own schedule and the applicant settles the
     * balance on theirs, so either can be the last to happen; whichever it is
     * has to be the one that issues. `isFullyCleared` already asks both
     * questions — every review done, every visit passed, nothing outstanding —
     * so this is a re-ask of the same question at the other moment, and not a
     * second copy of the rule.
     */
    public function releaseIfSettled(Application $app): void
    {
        if ($this->isFullyCleared($app)) {
            $this->approveAndIssue($app);
        }
    }

    /**
     * A filing may not be approved until somebody has said which tier it is.
     *
     * The client: "On the admin side, choosing the Application category must be
     * required. The admin must not approve the application unless an
     * Application category is chosen."
     *
     * ── Why a null tier is the thing being refused ────────────────────────────
     *
     * `complexity` is a column on the APPLICATION — one value per filing,
     * shared by every office on it — not a per-office field. So this is not a
     * task each of the seven offices has to repeat: whichever office fills the
     * blank first satisfies it for all of them, and the second office through
     * finds it already set and is not blocked by a field it cannot see a reason
     * to touch. There is also no way back to null — `classify()` only accepts a
     * real tier — so a filing that clears this once clears it forever.
     *
     * `submit()` seeds a tier from `Ra11032::tierFor()`, so filings created
     * through the product arrive here already non-null and this gate is quiet
     * on them. It is not therefore decorative. Null is reachable three ways
     * that matter: rows that predate submission-time classification (the
     * register holds live ones — a `for_inspection` filing and two under
     * review), anything seeded or fixtured without the column, and any future
     * path that stops guessing a tier at submission — which is the direction
     * open question A10 with BPLO points, since our guess is not the LGU's
     * published classification. This gate is what makes removing the guess safe
     * rather than silently issuing permits with no statutory clock on them.
     *
     * ── Approval only. Rejection is deliberately not gated ───────────────────
     *
     * A rejected filing never enters a processing clock, so demanding a tier
     * before `rejectApplication()` would stop an officer saying no for the sake
     * of a field that will never be measured. Returning for revision is
     * likewise untouched: the filing stays alive and can be categorised later.
     *
     * ValidationException so the officer gets a 422 carrying a sentence they
     * can act on, rather than IllegalTransitionException's status-machine
     * phrasing — nothing about the filing's STATUS is wrong here, and the fix
     * is a control on the screen they are already looking at.
     */
    private function requireProcessingCategory(Application $app): void
    {
        /*
         * A GUESS IS NOT A CHOICE, and this gate asks for a choice.
         *
         * The first version of this check asked only whether `complexity` held
         * a tier, and was therefore never once refused in ordinary use.
         * submit() seeds every filing from Ra11032::tierFor() — which, for a new
         * application with no high-tech line above the capital floor, falls
         * through to `complex` — so the column is never null and the gate never
         * fired. The client found it immediately: "how was this auto fill to
         * complex / also i shouldnt be able to approve if i havent selected an
         * application category."
         *
         * They are the same defect seen from two sides. The guess is a
         * convenience, made from the filing type and the declared capital, and
         * it is explicitly not the LGU's published classification — open
         * question A10 with BPLO. Letting it satisfy a rule that exists to make
         * an officer look would have meant the statutory clock on every filing
         * was set by a fall-through branch that nobody read.
         *
         * So the question is who set it, not whether it is set. The register has
         * recorded that since the tier became editable: null means the system
         * guessed, a user id means an officer put their name to it, and
         * ApplicationResource already publishes the difference as
         * `ra11032.source` = automatic | officer.
         */
        if (Ra11032::isTier($app->complexity) && $app->complexity_set_by_user_id !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'complexity' => ['Choose this application’s processing category before approving it. The category shown was assigned automatically from the filing type and the declared capital — nobody has checked it against the Citizen’s Charter. Confirm it or change it under For Office Use Only, then approve.'],
        ]);
    }

    /**
     * Officer approves their department's review.
     *
     * The guard is on the FILING, not on the assignment, and that distinction
     * is the bug (INS-5). An open assignment is not permission to act when the
     * filing underneath it is dead: rejecting an application deliberately does
     * not touch assignments — `rejectApplication()` sets the filing's status and
     * the rejection reason and stops — so every office that had not yet
     * finished reading keeps a `pending` row forever. That row is a record of
     * what was outstanding when the decision came, not a live task. 101
     * rejected filings in the register carry one, and each was a still-pressable
     * Approve.
     *
     * Refusing here rather than only in transition() so nothing is written at
     * all: without this the assignment is marked `completed` with a
     * `completed_at`, and only then does the status change blow up — leaving a
     * rejected filing carrying a freshly-approved review that no officer
     * intended and no rollback removes. The write and the refusal must not be
     * in that order.
     *
     * The processing-category gate is here for the same reason and in the same
     * position — before any write. See requireProcessingCategory().
     */
    public function approveAssignment(ApplicationAssignment $assignment, ?string $remarks = null): void
    {
        $app = $assignment->application;
        if ($app->status?->isTerminal()) {
            throw IllegalTransitionException::refuse($app->status, ApplicationStatus::ForInspection);
        }

        $this->requireProcessingCategory($app);

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
        /*
         * Review progress only means anything while the filing is IN review.
         *
         * `returned` is the case that matters and the one that was reachable
         * through the UI. Office A returns the filing for revision; office B
         * opens the same filing, is offered a live Approve — its own assignment
         * really is pending — presses it, and before this guard existed the
         * filing went `returned → for_inspection`. The applicant's revision
         * request vanished with no message and no history of anyone cancelling
         * it, and the only thing that restores a returned assignment is
         * `resubmit()`, which the applicant can no longer reach because their
         * Resubmit button renders on a returned filing.
         *
         * Nothing is booked either, deliberately. Sending an inspector to
         * premises whose filing is mid-revision books a visit against documents
         * that are about to change; the visit is booked when the office
         * approves a filing that is actually under review, which is what
         * happens after `resubmit()`.
         *
         * The office's own approval is still RECORDED — approveAssignment() has
         * already written it and that is not undone here. Its reading of the
         * paperwork genuinely is finished; what it does not do is move a filing
         * that is in someone else's hands.
         */
        if (! in_array($app->status, [ApplicationStatus::UnderReview, ApplicationStatus::ForInspection], true)) {
            return;
        }

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
        /*
         * Clear to ISSUE, which is a stronger claim than "the work is done".
         *
         * Both callers turn a true here straight into approveAndIssue(), and
         * that mints permits and writes `decided_at` BEFORE transition() is
         * reached — so a filing that may not legally become Approved has to be
         * stopped here, not there. Two live cases:
         *
         *  - `returned`. Every office happens to have completed and a visit
         *    passes while the applicant is revising: without this the permits
         *    are issued against the version they are in the middle of
         *    replacing.
         *  - `approved`. recordInspection() on a filing already issued —
         *    a re-inspection conducted after the fact — would run
         *    approveAndIssue() a second time and create a DUPLICATE permit row
         *    per permit type. transition() would then no-op on Approved →
         *    Approved and hide it. The permits would be real and numbered.
         *
         * Asking the legality table rather than listing statuses here keeps one
         * statement of the machine. If Approved ever becomes reachable from
         * somewhere new, this follows automatically.
         */
        if (! $app->status?->canTransitionTo(ApplicationStatus::Approved)) {
            return false;
        }

        /*
         * Money, and it is a "not yet" here rather than a refusal.
         *
         * approveAndIssue() throws on an outstanding balance, because a caller
         * that asks for permits to be minted on a filing that owes money has
         * asked for something wrong and should be told. This path is different:
         * both callers are an officer approving their own review or an
         * inspector recording their own visit, and neither of them is doing
         * anything wrong. The filing simply waits — parked in the status it is
         * already in — until the applicant settles the balance, at which point
         * onPaymentCompleted → releaseIfSettled() asks this same question again
         * and issues.
         *
         * Getting this wrong is the bug the last build of this design shipped:
         * with the refusal on this path, approving the last review threw inside
         * the transaction that had just marked it completed, so the officer got
         * a 422 and their own sign-off rolled back — an office locked out of
         * finishing its work by somebody else's unpaid bill.
         */
        if (PermitFees::hasOutstandingBalance($app)) {
            return false;
        }

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
     * ── The release gate ──────────────────────────────────────────────────────
     *
     * Rule 6 of docs/clearances-after-payment.md, and the reason the accrual is
     * not decoration: no permit leaves this building while the filing owes
     * money. A clearance applied for after the first payment adds its office's
     * fee lines to the assessment, and this is the only thing in the system
     * that makes the applicant settle them — without it they could apply for a
     * Fire Safety Inspection Certificate, have BFP work it, collect the
     * certificate, and never pay the ₱881.
     *
     * A hard throw, and a ValidationException so the caller gets a 422 carrying
     * a sentence rather than a stack trace. Both automatic callers reach this
     * through isFullyCleared(), which asks the same question first and answers
     * "not yet" instead — so in ordinary operation this never fires. It is here
     * for the direct caller: minting a legal instrument is not an act that
     * should be able to skip the check by choosing a different entry point.
     *
     * ASSUMPTION, recorded because the client asked for a best fit rather than
     * a question: the gate is on the FILING, not per permit. A filing owing
     * money for its sanitary clearance does not get its Mayor's Permit either,
     * even though the Mayor's Permit was paid for in full at submission.
     * Issuing per-permit against a per-filing ledger would need the payments to
     * be attributed to individual permit types, which the schema does not do
     * and which nobody has asked for.
     *
     * ── The processing-category gate, restated at the till ────────────────────
     *
     * This is the only place an application actually becomes Approved, and both
     * routes to it — the last office's review (afterReviewProgress) and the
     * last passing inspection (recordInspection) — come through here, so the
     * client's rule is enforced here as well as on the officer's Approve.
     *
     * It is not redundant with the gate on approveAssignment(). isFullyCleared()
     * requires every assignment `completed`, and after this change no assignment
     * can be completed on an uncategorised filing — but assignments completed
     * BEFORE this change are already in the register, and one of them is a
     * `for_inspection` filing with all seven reviews in and no category. The
     * approval path that filing will take is the inspection one, which never
     * touches approveAssignment. Without this, the last inspector's Pass mints
     * seven permits on a filing carrying no statutory deadline at all.
     *
     * The refusal reaches an inspector, not the officer who can fix it, and
     * that is the honest cost of the gate rather than an oversight: any office
     * on the filing can still set the category through
     * `POST /assignments/{id}/classification`, and the review sheet offers that
     * control on a filing in this state (see ReviewPage's For Inspection box,
     * which draws it precisely when the category is missing).
     */
    public function approveAndIssue(Application $app): void
    {
        $this->requireProcessingCategory($app);

        if (PermitFees::hasOutstandingBalance($app)) {
            $balance = PermitFees::balance($app);
            throw ValidationException::withMessages([
                'balance_due' => [
                    'This application still owes '.PermitFees::peso($balance['balance_due'])
                    .' on the clearances applied for after payment. Permits are released once the balance is settled.',
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
