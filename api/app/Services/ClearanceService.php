<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationOfficeForm;
use App\Models\PermitType;
use App\Support\Audit;
use App\Support\HeldPermits;
use App\Support\PermitFees;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The LGU clearance stage (docs/clearances-after-payment.md).
 *
 * PAYMENT FIRST, CLEARANCES AFTER. The wizard files the business permit alone;
 * that Tax Order of Payment is settled; and only then do the six clearances
 * open. Applying for one re-assesses the filing so that office's lines join a
 * running balance, and no permit is released until the balance reaches zero.
 *
 * The stage briefly worked the other way — the six were ticked inside the
 * wizard and one Tax Order of Payment covered the lot — and the ordering has
 * been reversed back at the client's instruction. Four mechanisms come back
 * with it, and they are one mechanism seen from four sides, so do not remove
 * any of them alone:
 *
 *   1. the unlock (isUnlocked below) — the stage is shut until money lands;
 *   2. the accrual (apply/unapply re-assess onto the same FeeAssessment);
 *   3. the second payment (PaymentController::pay charges `balance_due`);
 *   4. the release gate (WorkflowService::approveAndIssue).
 *
 * Delete the gate and the balance is decoration. Delete the accrual and the
 * gate never fires. Delete the second payment and the balance is owed,
 * blocking, and unpayable — which is exactly the bug the last build of this
 * shipped, and PaymentController's docblock records why the endpoint is not
 * restricted to `pending_payment`.
 *
 * What was right under either ordering and is untouched: FeeCalculator::assess
 * gates every rule on the requested permit types, so re-assessing after a
 * clearance is attached produces exactly that clearance's lines and nothing
 * else. The accrual is a re-assessment plus a record of what has been paid, not
 * a second pricing model.
 */
class ClearanceService
{
    /**
     * The six clearances, in the flow's order.
     *
     * Read from the permit_types table rather than from a hardcoded list of
     * six, minus the outcome permit: the set of clearances is data, and an LGU
     * that seeds a seventh office should get a seventh card without a code
     * change. CLEARANCE_ORDER only decides where they sit.
     *
     * @return Collection<int, PermitType>
     */
    public function clearanceTypes(): Collection
    {
        $order = array_flip(PermitType::CLEARANCE_ORDER);

        return PermitType::with('department')
            ->where('code', '!=', PermitType::OUTCOME_CODE)
            ->get()
            ->sortBy(fn (PermitType $pt) => [
                $order[$pt->code] ?? count($order),
                $pt->id,
            ])
            ->values();
    }

    /** One clearance by code, or null when the code is not a clearance. */
    public function findClearance(string $code): ?PermitType
    {
        $type = PermitType::with('department')
            ->where('code', strtoupper($code))
            ->first();

        return $type && $type->isClearance() ? $type : null;
    }

    /**
     * Everything the clearance screen renders, in one pass.
     *
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function overview(Application $application): array
    {
        $application->loadMissing('permitTypes', 'business.lines', 'permits', 'assignments.department');

        $types = $this->clearanceTypes();

        /*
         * The fee preview is what applying would add, so it needs a baseline to
         * subtract from — and the baseline has to be computed once, not once
         * per card. Null when the filing has no live business record: 139
         * applications in the register point at a soft-deleted business, and
         * FeeCalculator dereferences `business->lines` unguarded, so previewing
         * one of those would be a 500 rather than a price.
         */
        $baseline = $this->assessableTotal($application, $application->permitTypes);

        $rows = $types->map(fn (PermitType $type) => $this->row($application, $type, $baseline))->all();

        return ['rows' => $rows, 'meta' => $this->meta($application)];
    }

    /**
     * `meta` — whether the stage is open, and what the filing now owes.
     *
     * The ledger is part of the contract (docs/clearances-after-payment.md,
     * "API contract for the rebuild") and not a convenience: applying for a
     * clearance raises a balance the applicant has to be told about in the same
     * response that raised it, and the release gate refuses their permits until
     * they settle it. A screen that could not name the figure would show a
     * permit stuck behind money nobody had mentioned.
     *
     * All three figures rather than `balance_due` alone, because "you owe
     * ₱881" is not an answer on its own — the applicant needs to see that
     * ₱10,801 was assessed and ₱9,920 has cleared to recognise the ₱881 as the
     * fire clearance they pressed Apply on ten seconds ago.
     *
     * On a locked draft these read 0/0/0, which is honest here in a way it was
     * not under the old ordering: the stage is shut, `locked_reason` says why,
     * and nothing is quoting a zero as a price.
     *
     * @return array<string, mixed>
     */
    public function meta(Application $application): array
    {
        return [
            'unlocked' => $this->isUnlocked($application),
            'locked_reason' => $this->lockedReason($application),
            ...PermitFees::balance($application),
        ];
    }

    /**
     * One clearance card.
     *
     * @param  float|null  $baseline  total with the filing's current permit types
     * @return array<string, mixed>
     */
    public function row(Application $application, PermitType $type, ?float $baseline = null): array
    {
        $application->loadMissing('permitTypes', 'business.lines', 'permits', 'assignments.department');
        $baseline ??= $this->assessableTotal($application, $application->permitTypes);

        $held = HeldPermits::find($application, $type);
        $assignment = $this->assignmentFor($application, $type);
        $form = $this->officeFormFor($application, $type);

        return [
            'permit_type' => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'department' => $type->department ? [
                    'code' => $type->department->code,
                    'name' => $type->department->name,
                ] : null,
            ],
            'state' => $this->state($application, $type, $held !== null),
            'has_office_form' => $type->hasOfficeForm(),
            /*
             * "Saved at all", not "every field answered". The FSIC sheet's every
             * answer is derived by OfficeFormController, so it legitimately
             * posts an empty object — a completeness test that counted keys
             * would call that sheet permanently unfinished. The row existing is
             * the applicant having opened the form and saved it.
             */
            'office_form_complete' => $type->hasOfficeForm() && $form !== null,
            'held_document' => $held ? [
                'id' => $held->id,
                'name' => $held->original_filename,
                'size' => (int) $held->size_bytes,
                'download_url' => url("/api/v1/documents/{$held->id}/download"),
            ] : null,
            'assignment' => $assignment ? [
                'id' => $assignment->id,
                'status' => $assignment->status?->value,
                'remarks' => $assignment->remarks,
            ] : null,
            'fee_preview' => $this->feePreview($application, $type, $baseline),
        ];
    }

    // --- state ---------------------------------------------------------------

    /**
     * One value, in precedence order, because the screen renders one badge.
     *
     * `rejected` is in the contract's union but nothing here can produce it
     * yet: AssignmentStatus has no Rejected case, and what a rejected clearance
     * does to the business permit is checklist item 80 — an open question the
     * spec deliberately declines to guess. Returned is not rejected; it means
     * "fix this and come back", and the filing is already in the Returned state
     * saying so. The value stays in the contract so answering item 80 later
     * does not change the screen's shape.
     */
    private function state(Application $application, PermitType $type, bool $hasHeld): string
    {
        $issued = $application->permits->contains(fn ($p) => $p->permit_type_id === $type->id);
        if ($issued) {
            return 'issued';
        }
        if ($this->isAppliedFor($application, $type)) {
            return 'applied';
        }

        return $hasHeld ? 'submitted' : 'available';
    }

    public function isAppliedFor(Application $application, PermitType $type): bool
    {
        return $application->permitTypes->contains(fn ($pt) => $pt->id === $type->id);
    }

    /**
     * The assignment that carries this clearance's review.
     *
     * Matched on the issuing department, which is how WorkflowService has
     * always keyed assignments — one row per office, not per permit type. Every
     * seeded permit type has its own department today, so the mapping is 1:1;
     * if an LGU ever routed two clearances to one office they would share a
     * row, which is the same thing the officer's queue would show.
     */
    private function assignmentFor(Application $application, PermitType $type): ?ApplicationAssignment
    {
        return $application->assignments
            ->firstWhere('department_id', $type->issuing_department_id);
    }

    private function officeFormFor(Application $application, PermitType $type): ?ApplicationOfficeForm
    {
        return ApplicationOfficeForm::where('application_id', $application->id)
            ->where('permit_type_id', $type->id)
            ->first();
    }

    // --- unlocking -----------------------------------------------------------

    /**
     * The stage opens when the FIRST payment clears, and not before.
     *
     * ── Why the first payment and not submission ──────────────────────────────
     *
     * ASSUMPTION, taken deliberately and recorded here so it can be argued
     * with: the client said "payment first, then the others", and the honest
     * reading of "first" is the money, not the paperwork. Unlocking at
     * submission would open a chargeable stage on a filing the LGU has not been
     * paid a peso for, so an applicant could apply for six clearances, route
     * six offices, and abandon the filing before settling anything. The first
     * payment is the point at which the applicant has committed and the offices
     * can safely be given work.
     *
     * ── Why the ledger and not the status ─────────────────────────────────────
     *
     * `PermitFees::hasClearedPayment` rather than a `status !== PendingPayment`
     * test or a new `clearances_unlocked` column. The stage is about money, so
     * it asks the money. A column would be a second copy of a fact the payments
     * table already states, and the first time the two disagreed the applicant
     * would be looking at a stage that says one thing and a bill that says
     * another.
     *
     * ── The two statuses that stay shut even after paying ─────────────────────
     *
     * Rejected and Cancelled. There is nothing to apply for under a filing the
     * LGU has closed, and a clearance applied for on one would raise a balance
     * against an application that can never issue anything.
     *
     * Approved is deliberately NOT in that list. ASSUMPTION (spec's own open
     * question, answered "yes" to keep moving): a business that adds a food
     * line in June needs a sanitary permit it did not need in January, and the
     * data model allows applying for one against the filing that already gave
     * it its Mayor's Permit — a new assignment is routed, the fee joins the
     * balance, and the release gate is satisfied for the permits already out.
     * It is allowed here and not surfaced on the screen; if BPLO says a closed
     * filing is closed, this is the one line that changes.
     *
     * Returned is open, and that is the deliberate reversal of the old rule. A
     * returned filing has already paid, so it is past the gate; it is also the
     * one moment an office has told the applicant something is missing, and
     * "you also need a locational clearance" is a thing offices say.
     */
    public function isUnlocked(Application $application): bool
    {
        if (in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true)) {
            return false;
        }

        return PermitFees::hasClearedPayment($application);
    }

    /**
     * The sentence the screen shows verbatim while the stage is locked.
     *
     * Phrased as the next thing to do rather than as the fact of being locked:
     * "Locked" is what the applicant can already see, and a reason that only
     * restates it leaves them looking for the button that opens it. Every
     * sentence here therefore names the step that opens the stage, and a draft
     * gets a real one — under the old ordering a draft was where the stage was
     * open, so this returned null and the screen had nothing to say.
     */
    public function lockedReason(Application $application): ?string
    {
        if ($this->isUnlocked($application)) {
            return null;
        }

        return match ($application->status) {
            ApplicationStatus::Draft => 'Finish and submit this application first, then settle the Tax Order of Payment for your business permit. The six LGU clearances open here the moment that payment clears.',
            ApplicationStatus::Submitted => 'Your Tax Order of Payment is being prepared. Settle it and the six LGU clearances open here — you can apply for them one at a time, and each one’s fee is added to your balance.',
            ApplicationStatus::PendingPayment => 'Settle the Tax Order of Payment for your business permit. The six LGU clearances open here the moment that payment clears.',
            ApplicationStatus::Rejected => 'This application was not approved, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            ApplicationStatus::Cancelled => 'This application was cancelled, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            /*
             * Under review, for inspection, returned, approved with no cleared
             * payment behind them. Not reachable through the product — a filing
             * only leaves `pending_payment` by paying — but reachable in the
             * register, where officers have moved filings by hand. Say the true
             * thing rather than assume it away.
             */
            default => 'The LGU clearances open once the first payment on this application has cleared. Ours shows nothing settled yet — contact the BPLO if you have already paid.',
        };
    }

    // --- apply / un-apply ----------------------------------------------------

    /**
     * Apply for a clearance: attach it, bill it, and route it — one act.
     *
     * All three, in one transaction, because a filing carrying a clearance it
     * has not been billed for and no office has been told about is not a state
     * worth being able to reach. The three are the whole of the reversed
     * ordering:
     *
     * ── It re-assesses ────────────────────────────────────────────────────────
     *
     * The Tax Order of Payment produced at submit covered the business permit
     * alone. Attaching this permit type and re-running `assessFees` rewrites
     * that same FeeAssessment row with this office's lines added — and only
     * this office's, because FeeCalculator gates every rule on the requested
     * permit types. `total_paid` does not move, so the difference IS the
     * balance: the accrual is a re-assessment plus the payments ledger, not a
     * second pricing model.
     *
     * Only when the filing has already been assessed. In the flow it always
     * has — the stage cannot open until the first payment clears, and payment
     * implies an assessment — but a direct caller reaching this on an
     * unassessed filing must not have a Tax Order of Payment invented for it.
     *
     * An officer-adjusted assessment IS overwritten by this, and that is the
     * known cost of keeping one assessment row per filing. The alternative —
     * preserving the adjustment and adding to it — would mean re-deriving which
     * lines were the officer's, and a wrong guess there is a wrong bill.
     *
     * ── It routes ─────────────────────────────────────────────────────────────
     *
     * Rule 7: each clearance routes to its own office when it is applied for,
     * not when the application is submitted. It has to be here now —
     * `routeToDepartments` ran at payment and is long past by the time this
     * stage opens, so a clearance applied for afterwards would otherwise sit on
     * the filing with no office ever seeing it.
     *
     * The objection to apply-time routing under the old ordering was real and
     * no longer applies: `assigned_at` starts the service-time clock that
     * ProcessingTimeAnalytics, StaffingSimulation and DashboardAnalytics
     * measure an office by, and stamping it inside somebody's unfinished draft
     * charged the office for the days the applicant spent typing. There is no
     * draft here. The stage opens on a paid filing, so `assigned_at` is stamped
     * the moment the office genuinely has work.
     */
    public function apply(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            $application->permitTypes()->syncWithoutDetaching([$type->id]);
            $application->load('permitTypes');

            app(WorkflowService::class)->routeClearance($application, $type);
            $this->reassess($application);

            Audit::log('clearance.applied', $application, ['permit_type' => $type->code]);
        });
    }

    /**
     * Un-apply: take the permit type back off the draft.
     *
     * The office form's saved answers stay. They are the applicant's own words,
     * re-applying is a plausible next move, and silently discarding a filled
     * sheet to tidy up a table is a worse outcome than an orphan row that
     * nobody reads until it is relevant again. (It is also what lets the
     * clearance card promise, in the Submit dialog, that nothing typed into the
     * sheet is lost by changing your mind — see ClearanceStagePage.)
     *
     * Detaching is the exact inverse of apply, and all three halves have to
     * come off together: the fee lines leave the assessment (so the balance
     * falls by what the clearance added), the office's assignment is withdrawn
     * (so nobody reviews a request that was taken back), and the office-form
     * step stops being mandatory, because `selectedOfficeCodes` is derived from
     * the rows whose state is `applied`. That third one is the one that was not
     * obvious until it went missing (CLR-2): applying for MARKET, SANITARY or
     * OCCUPANCY inserts a step with required answers that Next will not walk
     * past, and with no caller for this method five real filings could not be
     * completed at all.
     *
     * ASSUMPTION, not modelled: a clearance fee already PAID is not refunded by
     * withdrawing. Re-assessing lowers `total_assessed`, `total_paid` stays put,
     * and `PermitFees::balance` floors the difference at zero rather than
     * reporting a credit — an overpayment is a conversation with the treasury,
     * not a wallet balance to spend on the next clearance. The spec lists
     * refundability as an open question with BPLO.
     */
    public function unapply(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            $application->permitTypes()->detach($type->id);
            $application->load('permitTypes');

            app(WorkflowService::class)->withdrawClearanceRouting($application, $type);
            $this->reassess($application);

            Audit::log('clearance.unapplied', $application, ['permit_type' => $type->code]);
        });
    }

    /**
     * Re-price the filing over the permit types it now carries.
     *
     * One FeeAssessment row per application, rewritten — not a second row and
     * not a delta row. Everything downstream reads `feeAssessment->total_amount`
     * as "what this filing costs", and a second row would make that question
     * ambiguous everywhere at once: the receipt, the officer's fee panel, the
     * balance, the analytics revenue figures.
     *
     * Guarded on an assessment already existing. See apply() — the stage cannot
     * open before the first payment, so in the flow one always does; the guard
     * is for a direct caller, so that reaching this on an unsubmitted filing
     * cannot invent a Tax Order of Payment for it.
     */
    private function reassess(Application $application): void
    {
        if ($application->feeAssessment()->doesntExist()) {
            return;
        }

        app(WorkflowService::class)->assessFees($application);
        $application->load('feeAssessment');
    }

    /**
     * Has this clearance's office already acted? Then it cannot be withdrawn.
     *
     * An office that has completed its review has done the work the fee paid
     * for, and a returned assignment is that office asking for something —
     * letting the applicant delete the question instead of answering it would
     * make "returned" a state you can escape by pressing cancel.
     */
    public function officeHasActed(Application $application, PermitType $type): bool
    {
        $assignment = $this->assignmentFor($application, $type);

        return $assignment !== null && in_array(
            $assignment->status,
            [AssignmentStatus::InProgress, AssignmentStatus::Completed, AssignmentStatus::Returned],
            true
        );
    }

    // --- fee preview ---------------------------------------------------------

    /**
     * What applying for this clearance adds (or, once applied, did add).
     *
     * Computed as a difference of two whole assessments rather than by picking
     * rules apart: FeeCalculator's aggregation steps (the sanitary inspection
     * fee is the highest matching rate, not the sum) mean a clearance's lines
     * are not separable from the total they land in.
     *
     * Nothing is written by any of this. It is a quote — what pressing Apply
     * will add to the balance — shown before the applicant commits to it, and
     * it matters more under this ordering than it did under the other one:
     * applying no longer just changes a bill they have yet to receive, it
     * creates money owed on a filing they have already paid for.
     */
    private function feePreview(Application $application, PermitType $type, ?float $baseline): ?string
    {
        if ($baseline === null) {
            return null;
        }

        $applied = $this->isAppliedFor($application, $type);

        // Applied: compare against the filing without it, so the number reads
        // "this is what it is costing you". Available: compare against it with.
        $counterfactual = $applied
            ? $application->permitTypes->reject(fn (PermitType $pt) => $pt->id === $type->id)->values()
            : $application->permitTypes->concat([$type]);

        $other = $this->assessableTotal($application, $counterfactual);
        if ($other === null) {
            return null;
        }

        $delta = $applied ? $baseline - $other : $other - $baseline;

        return PermitFees::peso(round(max(0, $delta), 2));
    }

    /**
     * The assessed total this filing WOULD have with exactly these permit types.
     *
     * Nothing is written: a clone carries the substituted relation, and
     * FeeCalculator::assess only reads. `loadMissing` inside it is what makes
     * this work — a relation already set is left alone — and cloning rather
     * than mutating keeps the caller's own loaded relations intact.
     *
     * Null when the business is gone: FeeCalculator reads `business->lines`
     * with no guard once a filing has no explicit fee_profile lines, so this
     * would be a fatal rather than a figure.
     *
     * @param  Collection<int, PermitType>|\Illuminate\Support\Collection<int, PermitType>  $types
     */
    private function assessableTotal(Application $application, $types): ?float
    {
        if ($application->business === null) {
            return null;
        }

        $probe = clone $application;
        $probe->setRelation('permitTypes', new Collection($types->all()));
        $probe->setRelation('business', $application->business);

        return (float) app(FeeCalculator::class)->assess($probe)['total'];
    }
}
