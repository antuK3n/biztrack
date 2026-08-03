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
 * The six clearances used to be step 4 of the apply wizard, which made them
 * look like part of one filing. They are not: each is a separate transaction
 * with a separate office, a separate fee and a separate outcome. Here they are
 * their own stage, opened by the first payment clearing, and each one applied
 * for re-assesses the filing so its office's lines join the running balance.
 *
 * The accrual is not a new pricing model. FeeCalculator::assess already gates
 * every rule on the selected permit types, so attaching a clearance and
 * re-running the assessment produces exactly that office's lines and nothing
 * else — verified per code before this was built on, not assumed.
 */
class ClearanceService
{
    public function __construct(private WorkflowService $workflow) {}

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
     * `meta` — whether the stage is open, and the running ledger.
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
     * The stage opens on payment, not on submission (spec rule 3).
     *
     * A decided filing is closed to it either way: applying for a clearance on
     * a rejected or cancelled application would put an assignment on an office
     * queue for a filing nobody is working.
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
     * restates it leaves them looking for the button that opens it.
     */
    public function lockedReason(Application $application): ?string
    {
        if ($this->isUnlocked($application)) {
            return null;
        }

        return match ($application->status) {
            ApplicationStatus::Draft => 'Finish and submit this application first — the six LGU clearances open once you have paid the assessed fees for your business permit.',
            ApplicationStatus::Submitted, ApplicationStatus::PendingPayment => 'Pay your Tax Order of Payment for the business permit. The six LGU clearances open as soon as that first payment clears, and each one you apply for is added to the same balance.',
            ApplicationStatus::Returned => 'Answer what the office sent back and resubmit, then pay your Tax Order of Payment — the six LGU clearances open once that first payment clears.',
            ApplicationStatus::Rejected => 'This application was not approved, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            ApplicationStatus::Cancelled => 'This application was cancelled, so no further clearances can be applied for under it. File a new application if you still need these clearances.',
            // Under review or later with no payment on record: possible on data
            // migrated in before the payment step existed. Say what is missing
            // rather than inventing a step the applicant cannot take.
            default => 'The six LGU clearances open once a payment on this application has cleared. No payment is recorded against it yet — contact the BPLO if you have already paid.',
        };
    }

    // --- apply / un-apply ----------------------------------------------------

    /**
     * Apply for a clearance: attach the permit type, re-assess, route it.
     *
     * All three in one transaction because they are one act. A permit type
     * attached without its fee is a free clearance; a fee assessed without an
     * assignment is a charge nobody is working.
     */
    public function apply(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            $application->permitTypes()->syncWithoutDetaching([$type->id]);
            $application->load('permitTypes');

            $this->workflow->assessFees($application);
            $this->workflow->routeClearance($application, $type);

            Audit::log('clearance.applied', $application, ['permit_type' => $type->code]);
        });
    }

    /**
     * Un-apply: detach, re-assess back down, and withdraw the routing.
     *
     * The office form's saved answers stay. They are the applicant's own words,
     * re-applying is a plausible next move, and silently discarding a filled
     * sheet to tidy up a table is a worse outcome than an orphan row that
     * nobody reads until it is relevant again.
     */
    public function unapply(Application $application, PermitType $type): void
    {
        DB::transaction(function () use ($application, $type) {
            $application->permitTypes()->detach($type->id);
            $application->load('permitTypes');

            $this->workflow->assessFees($application);
            $this->workflow->withdrawClearanceRouting($application, $type);

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
     * rules apart, for the same reason the accrual itself is a re-assessment:
     * FeeCalculator's aggregation steps (the sanitary inspection fee is the
     * highest matching rate, not the sum) mean a clearance's lines are not
     * separable from the total they land in.
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
