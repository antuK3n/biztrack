<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationOfficeForm;
use App\Models\ApplicationPermitType;
use App\Models\PermitType;
use App\Support\Audit;
use App\Support\HeldPermits;
use App\Support\PermitFees;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        /*
         * The permit's own status, straight through, because it now HAS one.
         *
         * This used to infer a state: a permit row exists → issued, the pivot
         * is attached → applied, a held copy is on file → submitted, otherwise
         * available. Every one of those was a proxy for a fact the schema did
         * not record, and the proxies disagreed with each other — a clearance
         * whose office had returned it read as `applied`, identical to one
         * nobody had opened.
         *
         * `application_permit_types.status` is that fact. The inference is gone
         * rather than kept as a fallback, because a fallback here is how a
         * genuine null quietly renders as a plausible wrong badge.
         */
        $row = $application->permitTypes->firstWhere('id', $type->id);
        $status = $row?->pivot?->status;

        if ($status !== null) {
            return $status->value;
        }

        /*
         * Not attached at all. For an optional permit that is the truth — it
         * has not been asked for. `hasHeld` still matters: a copy uploaded
         * before the permit was started is a half-finished action the applicant
         * should see reflected rather than lose.
         */
        return $hasHeld ? ClearanceStatus::NotStarted->value : 'available';
    }

    public function isAppliedFor(Application $application, PermitType $type): bool
    {
        return $application->permitTypes->contains(fn ($pt) => $pt->id === $type->id);
    }

    /** This permit's pivot row on this filing — the row that carries its status. */
    public function pivotRow(Application $application, PermitType $type): ?ApplicationPermitType
    {
        return ApplicationPermitType::where('application_id', $application->id)
            ->where('permit_type_id', $type->id)
            ->first();
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
     * The stage opens when the application is SUBMITTED.
     *
     * ── It used to be the first cleared payment, and that was wrong here ──────
     *
     * The reasoning for the money gate is kept because it is still the right
     * reasoning for a system that takes money: unlocking at submission opens a
     * chargeable stage on a filing the LGU has not been paid a peso for, so an
     * applicant could apply for six clearances, route six offices, and abandon
     * the filing before settling anything.
     *
     * It was wrong because payment in this build is a DUMMY. Nothing clears it,
     * so `hasClearedPayment` was false forever, every filing stayed locked, and
     * the six clearances were not "behind a gate" — they were unreachable. Two
     * separate testers reported them as missing and asked for them back. A gate
     * nobody can pass is indistinguishable from a deleted feature, and the
     * argument above is worth nothing if it protects a balance that no real
     * money ever reaches.
     *
     * The client's ordering is intact: the business permit is applied for
     * first, the Tax Order of Payment is still raised and still assessed, and
     * the clearances still come after. Only the blocking is gone — "just make
     * the payment kinda a nonsense step" (2026-09-02).
     *
     * WHEN PAYMENT BECOMES REAL, revisit this line, and put the question to
     * BPLO rather than answering it here: may an unpaid filing hold clearances?
     * If the answer is no, the restoration is `PermitFees::hasClearedPayment`
     * and the paragraph above is the argument for it.
     *
     * ── The statuses that stay shut ───────────────────────────────────────────
     *
     * Draft, because a draft is not yet an application; a clearance applied for
     * against one would raise a balance on a filing that may never be sent.
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
     * Returned is open. It is the one moment an office has told the applicant
     * something is missing, and "you also need a locational clearance" is a
     * thing offices say.
     */
    /*
     * ── The gate is submission, not payment [client instruction, 2026-09-02] ──
     *
     * This asked `PermitFees::hasClearedPayment()` and that made the six
     * clearances unreachable in practice. Payment in this build is a dummy: no
     * money moves and nothing clears it, so the gate never opened, every filing
     * sat locked, and testers reported the other permits as simply GONE. A gate
     * that no one can pass is indistinguishable from a deleted feature.
     *
     * The ORDER the client asked for on 28 August is unchanged — the business
     * permit is applied for first, the Tax Order of Payment is still raised and
     * still assessed, and the clearances still come after it. What changed is
     * that the payment no longer BLOCKS: "just make the payment kinda a
     * nonsense step" (2026-09-02). Submitting is what opens the stage.
     *
     * Draft stays closed, and that is the order surviving rather than an
     * oversight: a draft is not yet an application, and a clearance applied for
     * against one would raise a balance on a filing that may never be sent.
     *
     * When payment becomes real, this is the one line to reconsider — and the
     * question to put to BPLO then is whether an unpaid filing may hold
     * clearances at all, not whether this line should quietly go back.
     */
    /*
     * ── The gate is PAYMENT again [client, verified procedure, 2026-09-06] ────
     *
     * This has now been all three things, so the history matters. It began as
     * payment, moved to submission on 2 September because payment was a dummy
     * that never cleared — "a gate that no one can pass is indistinguishable
     * from a deleted feature" — and comes back to payment now that the counter
     * procedure has been checked against the real office.
     *
     * What makes it safe this time is that the thing it waits for actually
     * happens. Payment is the applicant's own action and completes
     * synchronously in the same press; nothing external has to clear. The 2
     * September failure was not that payment was the wrong gate, it was that
     * the gate was wired to an event the system never emitted.
     *
     * `isPaid()` is deliberately a list of paid statuses rather than "not one
     * of the unpaid ones", so a status added later is unpaid until somebody
     * says otherwise. Safe default for a gate that guards money.
     */
    public function isUnlocked(Application $application): bool
    {
        return $application->status?->isPaid() ?? false;
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
            ApplicationStatus::Draft => 'Finish and submit this application first. The six LGU clearances open here as soon as it is submitted, and you can apply for them one at a time — each one’s fee is added to your balance.',
            ApplicationStatus::Rejected => 'This application was not approved, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            ApplicationStatus::Cancelled => 'This application was cancelled, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            /*
             * Unreachable: `isUnlocked` now returns true for every status not
             * matched above, so this arm exists only so the method is total. It
             * says something true and useless rather than throwing, because a
             * status added to the enum later must not take the screen down.
             */
            default => 'These clearances are not open on this application yet.',
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
     * The objection to apply-time routing under the old ordering was real:
     * `assigned_at` starts the service-time clock that ProcessingTimeAnalytics,
     * StaffingSimulation and DashboardAnalytics measure an office by, and
     * stamping it inside somebody's unfinished draft charged the office for the
     * days the applicant spent typing.
     *
     * What answers it is NOT that this stage only opens on a paid filing — it
     * no longer does, since the gate moved to submission (see `isUnlocked`).
     * It is that `WorkflowService::routeClearance` refuses to route at all
     * until `PermitFees::hasClearedPayment`, so applying while unpaid attaches
     * the permit type and its fee and creates no assignment. The office is
     * given the work by `routeToDepartments` when the payment clears, and
     * `assigned_at` is stamped then.
     *
     * That guard is therefore load-bearing, and it is the thing to check before
     * anyone concludes an unpaid filing can be pushed into an office queue.
     */
    public function apply(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            app(WorkflowService::class)->startClearance(
                $application,
                $type,
                ApplicationPermitType::MODE_APPLY,
            );

            Audit::log('clearance.applied', $application, ['permit_type' => $type->code]);
        });
    }

    /**
     * The applicant hands in a permit they already hold.
     *
     * The other half of `apply`, and it goes through the same door on purpose.
     * Both put the permit into `for_approval` and both route it to its office;
     * the only difference is `mode`, which tells the office whether there is a
     * form to read or only an image.
     *
     * It does NOT skip the inspection, and that is the client's decision rather
     * than an oversight (6 September 2026): the LGU inspects the premises, not
     * the paperwork, so a business handing in last year's Fire Safety
     * certificate is still visited. Nor does it reduce the fee — the bill was
     * settled at submission and charges for a permit either way, because the
     * fee covers that inspection.
     */
    public function submitHeld(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            app(WorkflowService::class)->startClearance(
                $application,
                $type,
                ApplicationPermitType::MODE_UPLOAD,
            );

            Audit::log('clearance.held_submitted', $application, ['permit_type' => $type->code]);
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
        /*
         * A REQUIRED permit cannot be withdrawn, and this is the rule that
         * changed. Five of the six are mandatory now
         * (PermitType::REQUIRED_CLEARANCE_CODES), so "changing your mind" is not
         * a move that exists for them — the application cannot be approved
         * without them, and detaching one would leave a filing that has been
         * paid for and can never complete.
         *
         * Market Clearance is the one that can still come off, because it is
         * the one that was optional to begin with.
         */
        if ($type->isRequiredClearance()) {
            throw ValidationException::withMessages([
                'permit_type' => [$type->name.' is required on every application and cannot be withdrawn.'],
            ]);
        }

        DB::transaction(function () use ($application, $type) {
            $application->permitTypes()->detach($type->id);
            $application->load('permitTypes');

            /*
             * The fee is NOT re-assessed. One bill, raised at submission,
             * covering everything (docs/application-flow-2026-09.md rule 4) —
             * and by the time this stage is open the applicant has already paid
             * it. Re-pricing here would lower `total_assessed` below
             * `total_paid` on a filing whose money is already in, which is a
             * refund and not an assessment. Refundability is an open question
             * with BPLO; until it is answered, withdrawing an optional permit
             * costs what it cost.
             */
            ApplicationAssignment::where('application_id', $application->id)
                ->where('department_id', $type->issuing_department_id)
                ->where('status', AssignmentStatus::Pending->value)
                ->delete();

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
    /*
     * `reassess()` is gone.
     *
     * It re-priced the filing every time a clearance was applied for or
     * withdrawn, which was the whole point while clearances were chosen after
     * payment and accrued a running balance. There is no balance now: the bill
     * is raised once at submission over every permit the filing will need, and
     * the applicant pays it before the other permits even open
     * (docs/application-flow-2026-09.md rule 4).
     *
     * Nothing replaces it. If a second payment ever comes back — a clearance
     * choosable after the bill, an LGU adding a requirement mid-flight — this
     * method and the `PermitFees::hasOutstandingBalance` release gate come back
     * together, and the argument for both is in the superseded
     * `clearances-after-payment.md`.
     */

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
