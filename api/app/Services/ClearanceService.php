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
 * The LGU clearance stage (docs/clearances-before-payment.md).
 *
 * The six clearances are decided while the application is still being filled
 * in, and the whole filing — business permit and every clearance chosen — is
 * paid for once, after submission. So this service answers "which clearances
 * does this draft ask for", and nothing about money changing hands.
 *
 * It briefly worked the other way: the stage opened AFTER the first payment,
 * each clearance applied for re-assessed into a running balance, a second
 * payment settled it and a gate held the permit until it cleared. All four of
 * those mechanisms are gone (docs/clearances-after-payment.md carries the
 * post-mortem). Do not rebuild them — the accrual's only job was to price
 * choices made after the Tax Order of Payment had already been issued, and no
 * choice is made after it any more.
 *
 * What survives from that design, because it was right either way:
 * FeeCalculator::assess gates every rule on the selected permit types, so the
 * clearances attached here are exactly the office lines billed at submit. That
 * was verified per code rather than assumed, and it is why `assessFees` at
 * submit needs to know nothing about clearances.
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
     * `meta` — whether the stage is still open to being changed.
     *
     * It used to carry `total_assessed`, `total_paid` and `balance_due` as
     * well, because a clearance applied for after payment raised a balance the
     * screen had to show. There is no such balance now: nothing here is
     * chargeable until the filing is submitted, and by then this stage is shut.
     * A ledger on a draft would only ever read zero, and a zero that is really
     * "not assessed yet" is worse than no figure at all.
     *
     * @return array<string, mixed>
     */
    public function meta(Application $application): array
    {
        return [
            'unlocked' => $this->isUnlocked($application),
            'locked_reason' => $this->lockedReason($application),
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
     * The stage is open while the filing is still being written, and only then.
     *
     * This asked whether a payment had cleared, which is the inverse of the
     * rule the client settled on: payment is the LAST thing, so a filing that
     * has been paid for is a filing whose clearances were decided long ago.
     *
     * Draft, and deliberately not Returned — even though the documents and the
     * office sheets both stay editable while a filing is back with the
     * applicant. A returned filing has already been assessed and paid; adding a
     * chargeable clearance to it would raise a difference nobody is asked for
     * and nothing gates, which is the accrual this change exists to delete.
     * Whether a returned filing may gain a clearance at all is BPLO's call, and
     * it is not one to guess at by leaving a door open.
     */
    public function isUnlocked(Application $application): bool
    {
        return $application->status === ApplicationStatus::Draft;
    }

    /**
     * The sentence the screen shows verbatim while the stage is locked.
     *
     * Phrased as the next thing to do rather than as the fact of being locked:
     * "Locked" is what the applicant can already see, and a reason that only
     * restates it leaves them looking for the button that opens it.
     *
     * There is no sentence for a draft any more, because a draft is when the
     * stage is open. Everything below is a filing that has left the applicant's
     * hands, and the honest answer for all of them is the same one: the choice
     * was made at submission and this is not the place to change it.
     */
    public function lockedReason(Application $application): ?string
    {
        if ($this->isUnlocked($application)) {
            return null;
        }

        return match ($application->status) {
            ApplicationStatus::Submitted, ApplicationStatus::PendingPayment => 'Your clearances were decided when you submitted this application, and they are on the Tax Order of Payment you are about to settle. They can no longer be changed here — message the BPLO if one of them is wrong.',
            ApplicationStatus::Returned => 'Answer what the office sent back and resubmit. The clearances on this filing were fixed when it was submitted, so message the office if you now need one you did not ask for.',
            ApplicationStatus::Rejected => 'This application was not approved, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            ApplicationStatus::Cancelled => 'This application was cancelled, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            // Under review, for inspection, approved: the offices are working
            // the filing as submitted.
            default => 'This application is with the reviewing offices, so the clearances on it can no longer be changed. Message the BPLO if you need one you did not ask for.',
        };
    }

    // --- apply / un-apply ----------------------------------------------------

    /**
     * Apply for a clearance: attach the permit type to the draft.
     *
     * That is the whole of it now, and the two things it no longer does are
     * worth naming so they are not put back.
     *
     * It does not re-assess. There is nothing to re-assess against: the Tax
     * Order of Payment is produced once, by `assessFees` at submit, from
     * exactly the permit types sitting here. Writing a FeeAssessment row
     * against a draft would only invent a bill for a filing nobody has sent.
     * The card's `fee_preview` is computed on the fly (see feePreview below),
     * so the price is still quoted before the button is pressed.
     *
     * It does not route an assignment either. Routing happens at payment, in
     * WorkflowService::routeToDepartments, which already raises one assignment
     * per department owning a requested permit type — the clearances included.
     * Routing at apply time would stamp `assigned_at` the moment a card was
     * ticked in a draft, and `assigned_at → completed_at` is what
     * ProcessingTimeAnalytics, StaffingSimulation and DashboardAnalytics all
     * measure an office's service time with. An applicant who left their draft
     * open for a week would have added a week to CHO's measured turnaround.
     */
    public function apply(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            $application->permitTypes()->syncWithoutDetaching([$type->id]);
            $application->load('permitTypes');

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
     * Detaching does three things at once, and the third is the one that was
     * not obvious until it went missing (CLR-2): the fee lines come off the
     * assessment that has not been written yet; the office stops being routed
     * an assignment at payment; and the wizard's mandatory office-form STEP
     * disappears, because `selectedOfficeCodes` is derived from the rows whose
     * state is `applied`. Applying for MARKET, SANITARY or OCCUPANCY inserts a
     * step with required answers that Next will not walk past and the section
     * map will not jump over. With no caller for this method, five real drafts
     * could not reach Review & Submit at all. This is the only thing in the
     * system that makes that step go away.
     */
    public function unapply(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            $application->permitTypes()->detach($type->id);
            $application->load('permitTypes');

            Audit::log('clearance.unapplied', $application, ['permit_type' => $type->code]);
        });
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
     * Nothing is written by any of this. It is a quote — what this clearance
     * will add to the one Tax Order of Payment issued at submit — shown before
     * the applicant commits to it.
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
