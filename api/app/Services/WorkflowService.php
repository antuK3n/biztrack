<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use App\Exceptions\IllegalTransitionException;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationCorrection;
use App\Models\ApplicationPermitType;
use App\Models\ApplicationReturnNote;
use App\Models\ApplicationStatusHistory;
use App\Models\Business;
use App\Models\FeeAssessment;
use App\Models\Inspection;
use App\Models\OfficerRequest;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\UnbilledPermitFee;
use App\Models\User;
use App\Support\AmendableFields;
use App\Support\Audit;
use App\Support\BusinessDate;
use App\Support\ClearanceSnapshot;
use App\Support\DenrRequirements;
use App\Support\Numbering;
use App\Support\PermitFace;
use App\Support\PermitFees;
use App\Support\Ra11032;
use App\Support\RenewalSeason;
use App\Support\ReturnTargets;
use App\Support\SheetRequirements;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

        if (in_array($to, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true)) {
            $this->closeOpenReviews($app);
        }

        $this->notify->applicationStatus($app, $to, $note);
    }

    /**
     * A filing that has ended takes every office's open review with it.
     *
     * Here, in the one writer of `applications.status`, rather than in
     * `rejectApplication` and `cancel` separately, because the gap was exactly
     * that neither of them did it: BPLO rejecting a filing left CHO, BFP and
     * the rest holding `pending` reviews of something that no longer existed,
     * and a cancellation left BPLO's own. Those rows sat in every office's
     * For Approval tab and in its open backlog for good — 106 of them on the
     * copy of the register this was measured on. Any route to Rejected or
     * Cancelled added later is covered without remembering to.
     *
     * Closed, not Completed: see AssignmentStatus::Closed for why
     * `completed_at` stays null.
     */
    private function closeOpenReviews(Application $app): void
    {
        $open = ApplicationAssignment::where('application_id', $app->id)
            ->whereIn('status', AssignmentStatus::openValues())
            ->get();

        foreach ($open as $assignment) {
            $assignment->update(['status' => AssignmentStatus::Closed]);
            Audit::log('assignment.closed', $assignment, ['application_status' => $app->status?->value]);
        }
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
     * No history TABLE for the pivot, and there still is not one. The client
     * asked for a per-permit timeline on 26 September 2026 — the moment this
     * note reserved — and it is served by a `permit_type_id` COLUMN on
     * `application_status_history` rather than by the second table this
     * paragraph warned about. One table, two timelines, told apart by that
     * column; the audit log stays the system of record and the history rows
     * are what the applicant reads.
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
        /*
         * The permit's own history, since 26 September 2026 — the moment the
         * note below said to wait for. Written beside the filing's rows in
         * `application_status_history` and told apart by `permit_type_id`.
         *
         * `department_id` is the office that ISSUES this permit, not the one
         * that happens to be acting: a CENRO certificate returned by BPLO at
         * final approval is still CENRO's row, and a timeline that said BPLO
         * would have the applicant chasing the wrong counter.
         */
        ApplicationStatusHistory::create([
            'application_id' => $row->application_id,
            'permit_type_id' => $row->permit_type_id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by_user_id' => Auth::id(),
            'department_id' => $row->permitType?->issuing_department_id,
            'note' => $note,
        ]);
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
     * into an optional clearance during the wizard, and a plain sync would drop
     * it on the floor.
     *
     * ── A RENEWAL IS NOT EXPANDED ────────────────────────────────────────────
     *
     * This ran over every filing, and on a renewal it was simply wrong. The
     * applicant ticks which permits they are renewing — that is the whole point
     * of the entry dialog, and the client's rule (9 September 2026) is that they
     * may tick any subset, freely, because the six permits expire on six
     * different dates. A shop whose Sanitary Permit runs out in September and
     * whose FSIC runs until November renews the one, not both.
     *
     * What this did to that shop, measured before it was changed:
     *
     *     BEFORE submit: BUSINESS,SANITARY
     *     AFTER submit:  BUSINESS,SANITARY,FSIC,OCCUPANCY,CEC,ZONING
     *
     * Four permits they did not ask for, on one Tax Order of Payment they did
     * not expect, each gating the final approval of a filing that was only ever
     * about the sanitary permit. The applicant is billed for four renewals they
     * did not want and cannot proceed without completing four office forms for
     * clearances that are still valid.
     *
     * So the expansion is for NEW filings only, where it is right: a business
     * being registered for the first time needs all five, and rule 1 of
     * docs/application-flow-2026-09.md says so. A renewal keeps exactly the set
     * the applicant chose — including, per the same client decision, one that
     * does not carry the Mayor's Permit at all. Every reader of the business
     * permit's pivot row is already null-guarded for that case
     * (`approveMainForm`, `approveAndIssue`), because the row has always been
     * able to be absent on a draft.
     *
     * An amendment is left on the NEW path deliberately: it is a different
     * filing type with its own unresolved shape, the client has said they will
     * deal with it separately, and changing its permit set on the way past would
     * be a decision nobody made.
     */
    public function attachRequiredPermitTypes(Application $app): void
    {
        if ($app->application_type === ApplicationType::Renewal) {
            /*
             * Not "nothing", but "nothing the applicant did not ask for".
             *
             * The permit types of the TICKED PRIOR PERMITS are attached here,
             * as a guarantee rather than an expansion: ticking a permit in the
             * entry dialog is the applicant saying they are renewing it, and a
             * filing that named the permit but never carried its type would be
             * renewing nothing — no office form, no assignment, no fee, no
             * certificate at the end. The wizard sends the same set in
             * `permit_type_ids`; this makes the two agree even when the wizard
             * is not the caller, which is the same reason every other gate in
             * this flow is duplicated on the server.
             *
             * `syncWithoutDetaching`, so anything else already on the filing is
             * left alone, and the union is what the applicant gets.
             */
            $chosen = $app->priorPermits()
                ->pluck('permits.permit_type_id')
                ->unique()
                ->filter()
                ->all();

            if ($chosen !== []) {
                $app->permitTypes()->syncWithoutDetaching(
                    collect($chosen)
                        ->mapWithKeys(fn ($id) => [$id => ['status' => ClearanceStatus::NotStarted->value]])
                        ->all()
                );
            }

            /*
             * The rows the applicant chose still need a starting status: a
             * draft whose pivot was written before this column existed, or by
             * `ApplicationController::store`'s plain `sync`, would otherwise
             * submit with a null there and every reader of `isOutstanding()`
             * would have to guess.
             */
            $app->permitTypes()
                ->wherePivotNull('status')
                ->pluck('permit_types.id')
                ->each(fn ($id) => $app->permitTypes()->updateExistingPivot(
                    $id,
                    ['status' => ClearanceStatus::NotStarted->value],
                ));

            return;
        }

        $app->permitTypes()->syncWithoutDetaching(
            self::permitTypeIdsAtSubmission($app)
                ->mapWithKeys(fn ($id) => [$id => ['status' => ClearanceStatus::NotStarted->value]])
                ->all()
        );
    }

    /**
     * The permit types a filing WILL carry once submitted.
     *
     * Extracted so that the fee ESTIMATE and the actual bill are computed over
     * the same set. The estimate is shown on the wizard's fee step, before
     * submission, and at that moment the filing carries only the business
     * permit — `attachRequiredPermitTypes` has not run. An estimate assessed
     * over what the draft holds today would therefore quote the business
     * permit alone and omit five clearances the applicant is about to be
     * billed for, which is worse than showing no figure at all: a number that
     * is confidently wrong by several thousand pesos.
     *
     * A renewal keeps the set it chose, so the estimate is right for it
     * already; this only widens a NEW filing to the set submission will attach.
     *
     * @return Collection<int, int>
     */
    public static function permitTypeIdsAtSubmission(Application $app): Collection
    {
        if ($app->application_type === ApplicationType::Renewal) {
            return $app->permitTypes()->pluck('permit_types.id');
        }

        /*
         * ── An AMENDMENT carries the business permit alone ───────────────────
         *
         * Client, 19 September 2026: *"We have clarified with the LGU that only
         * business permit details can be amended."*
         *
         * Until then an amendment fell through to the line below and attached
         * all six types — the business permit and five clearances — so it was
         * billed for five certificates it was not asking for, routed to five
         * offices with nothing to review, and walked through Awaiting Other
         * Permits waiting on permits nobody had applied for. Changing a floor
         * area cannot require a fresh Fire Safety inspection.
         *
         * The one permit it does carry is the business permit, because that is
         * the document whose printed details the amendment changes.
         */
        if ($app->application_type === ApplicationType::Amendment) {
            /*
             * ── A move carries the Zoning clearance with it ──────────────────
             *
             * Client's decision, 19 September 2026: a change of address must
             * not be approved until CPDO has cleared the new location, and
             * CPDO should get a real filing rather than a notification.
             *
             * Those two together are circular if the zoning filing is raised
             * BY the approval that waits for it, so it is raised here instead —
             * at submission, as a clearance on the amendment itself. The
             * machinery is the one a new application already uses:
             * `startClearance` routes CPDO, `outstandingClearances` keeps the
             * filing open until the permit is issued, and `approveAmendment`
             * refuses while anything is outstanding.
             *
             * It also answers the second knot. CPDO has to assess the address
             * the business is MOVING TO, and the register still holds the old
             * one until the amendment is approved — so the new address cannot
             * be read from the business. Carrying zoning on the amendment puts
             * CPDO on the filing that states it: `application_amendments` holds
             * the proposed value, and the review sheet shows old → new.
             *
             * ── A change of activity or a larger area carries it too ─────────
             *
             * This said "only for an address change: amending a floor area or
             * a trade name tells CPDO nothing it assessed". The ordinance says
             * otherwise for two of the three. Art. IX §8: "Should there be any
             * change in the activity or expansion of the area subject of the
             * Locational Clearance, the owner/developer shall apply for a new
             * Locational Clearance" — repeated word for word in §9. CPDO did
             * assess the activity (it is item V on MCG-CPDD-FO-003) and the
             * floor area (item VIII.A, and the processing fee is charged per
             * square metre of it, §10.1(c)). So a new line of business, or a
             * floor area larger than the register's, now carries ZONING as a
             * move does. A smaller floor area does not (that is no expansion),
             * and neither does a trade name or an owner: Annex A 63 says a
             * change of tenant or proprietor is not a change of occupancy.
             *
             * A floor area with NO earlier figure on the register is not
             * treated as an expansion — there is nothing to compare, and
             * charging a fresh clearance on a guess is the five-clearance
             * mistake again in miniature. The zoning checklist tells CPDO and
             * BPLO it could not compare, so the call is a person's.
             *
             * ── And only when the PREMISES actually move ─────────────────────
             *
             * Not when the barangay changes, which was the rule until
             * 21 September 2026 and tested the wrong thing — zoning belongs to
             * a location, and two streets in one barangay can be zoned
             * differently. See `amendmentMovesPremises` for why the pin is the
             * signal and why BizTrack cannot judge the map itself.
             *
             * So: a pin dropped somewhere new sends the filing to CPDO;
             * correcting the spelling of a street does not.
             */
            $codes = [PermitType::OUTCOME_CODE];

            if (self::amendmentNeedsLocationalClearance($app) !== []) {
                $codes[] = 'ZONING';
            }

            return PermitType::whereIn('code', $codes)->pluck('id');
        }

        return PermitType::whereIn('code', array_merge(
            [PermitType::OUTCOME_CODE],
            PermitType::REQUIRED_CLEARANCE_CODES,
        ))->pluck('id');
    }

    /**
     * Whether this amendment MOVES THE PREMISES, and so needs CPDO to look.
     *
     * ── Why the pin and not the barangay ─────────────────────────────────
     *
     * This asked whether the barangay changed, which is the wrong question.
     * Zoning is a property of a LOCATION, not of a barangay: a residential
     * street and a commercial street sit inside the same barangay all the
     * time, so a business could move to a street that forbids its trade and
     * never trip a barangay test. Client, 21 September 2026: *"what if you
     * changed your street and that street now prohibits this type of
     * business, but also what if you are just correcting any typo."*
     *
     * The honest answer to "does the new street allow this?" is that BizTrack
     * cannot know. `ZoningClassification` says so in as many words — the
     * barangay sheets are raster images with no geometry, so no conformity
     * verdict is computable for any point and none is offered. Only a CPDO
     * officer reading the map can tell.
     *
     * So this does not judge zoning. It decides whether to ASK, and the one
     * signal the register genuinely holds is the pin. Correcting how an
     * address is WRITTEN does not move the premises and leaves the pin alone;
     * moving to another street moves it. A barangay change is covered by the
     * same rule, because the old pin cannot survive `checkPin` against a new
     * barangay and a new one has to be dropped.
     *
     * Static and public because two callers need the same answer and must not
     * be allowed to differ about it: `permitTypeIdsAtSubmission` decides
     * whether the filing carries a ZONING clearance, and the applicant's step
     * warns before they commit to one. A rule wired into one of two callers is
     * the defect this codebase keeps meeting.
     */
    public static function amendmentMovesPremises(Application $app): bool
    {
        return $app->requestedChanges()
            ->where('field', 'address_pin')
            ->whereNotNull('new_value')
            ->exists();
    }

    /**
     * Why this amendment needs a new locational clearance, if it does.
     *
     * A move (the pin), a change of activity (the line of business) or an
     * expansion of the area (a floor area larger than the register's) — City
     * Ordinance No. 24-2018, Art. IX §§8-9. Empty when none applies. See the
     * note in `permitTypeIdsAtSubmission` for what is deliberately left out.
     *
     * @return list<'moves'|'activity'|'area'>
     */
    public static function amendmentNeedsLocationalClearance(Application $app): array
    {
        $requested = $app->requestedChanges()->whereNotNull('new_value')->pluck('new_value', 'field');
        $why = [];
        if ($requested->has('address_pin')) {
            $why[] = 'moves';
        }
        if ($requested->has('line_of_business')) {
            $why[] = 'activity';
        }
        if ($requested->has('business_area_sqm')) {
            $before = $app->business()->withTrashed()->value('business_area_sqm');
            $after = $requested->get('business_area_sqm');
            if (is_numeric($before) && is_numeric($after) && (float) $after > (float) $before) {
                $why[] = 'area';
            }
        }

        return $why;
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

        $lineCount = max(1, $app->business->lines->count());

        $flatLine = fn (PermitType $pt) => [
            'label' => $pt->name.' fee (flat schedule)',
            'amount' => round((float) $pt->base_fee + ((float) $pt->per_line_surcharge * $lineCount), 2),
            // Attributed, so `deferredAmountFor` can find it later. See the
            // note on FeeCalculator::item().
            'permit_codes' => [$pt->code],
        ];

        if ($items === []) {
            // No rule matched anything at all: the whole filing falls back.
            $items = $app->permitTypes->map($flatLine)->all();
        } elseif ($app->defersPayment()) {
            /*
             * ── One zero-value rule used to suppress every other permit ──────
             *
             * The fallback above is all-or-nothing, and that was wrong on a
             * renewal carrying more than one clearance. Measured 19 September
             * 2026 on a Sanitary + FSIC renewal: ONE rule matched — the FSIC
             * Fire Code fee — and computed ZERO. That single zero-value line
             * made `$items` non-empty, which suppressed the fallback for the
             * Sanitary Permit as well, and the applicant was shown a bill of
             * ₱0.00 for two permits.
             *
             * So a permit with no non-zero line of its own gets the flat
             * schedule, rather than the whole filing getting it or nothing
             * getting it.
             *
             * Scoped to filings that DEFER, which is where the fault was found
             * and where the cost of being wrong is a permit issued unpaid.
             * Widening it to new applications would change what they are
             * billed — every clearance not covered by a non-zero rule would
             * gain a line — and that is a pricing decision for BPLO, not a
             * side effect of fixing a renewal.
             */
            $covered = collect($items)
                ->flatMap(fn (array $i) => ((float) ($i['amount'] ?? 0)) > 0
                    ? (array) ($i['permit_codes'] ?? [])
                    : [])
                ->unique();

            foreach ($app->permitTypes as $pt) {
                if ($covered->contains($pt->code)) {
                    continue;
                }

                /*
                 * Drop the zero-value line this permit is being substituted
                 * for. Leaving it printed "Fire Code fee ₱0" directly above
                 * "Fire Safety Inspection Certificate fee ₱860" — the same
                 * permit twice, one of them free, which reads as a mistake
                 * even though the total is right.
                 */
                $items = array_values(array_filter($items, fn (array $i) => ! in_array(
                    $pt->code,
                    (array) ($i['permit_codes'] ?? []),
                    true,
                )));

                $items[] = $flatLine($pt);
            }
        }

        /*
         * ── A line worth nothing is not printed ─────────────────────────
         *
         * A renewal walked end to end on 1 October 2026 came out with
         * "Garbage fee — Schedule T: bulky/special waste collection (old
         * furniture, appliances, construction debris/waste, earthmound, and
         * the like), per trip … ₱0.00" on a sari-sari store's Tax Order of
         * Payment. The rule matched, computed nothing, and printed a
         * sentence about construction debris on a corner shop's bill.
         *
         * A charge of zero is not a charge. It reads as something the
         * applicant might owe, invites a question at the counter, and
         * pushes the lines that ARE owed further down the page.
         *
         * AFTER the flat-schedule substitution above, which needs the zero
         * lines to decide which permits it covers, and BEFORE the sweep
         * below, which brings in deferred rows. Those keep their zeros on
         * purpose: `recordAmendmentFee` writes a ₱0 row so the amendment
         * appears on the January bill saying what it is for, ready for the
         * day BPLO names a figure. That is a line with a reason; this is a
         * rule that happened to compute nothing.
         */
        $items = array_values(array_filter(
            $items,
            fn (array $i) => round((float) ($i['amount'] ?? 0), 2) !== 0.0,
        ));

        $total = round(collect($items)->sum(fn (array $i) => (float) ($i['amount'] ?? 0)), 2);

        /*
         * ── And the fees January was waiting to collect ───────────────────────
         *
         * A business-permit renewal is the bill that settles everything the
         * business has been issued unpaid since the last one. Client's decision,
         * 17 September 2026 — see `recordDeferredFee` for the other half and
         * docs/renewal-2026-09-17.md for the whole rule.
         *
         * Only a filing that CARRIES the business permit sweeps. A clearance-only
         * renewal is the thing that defers; having it collect would make every
         * June renewal a bill for every earlier June, which is the opposite of
         * the decision.
         *
         * The claim is taken here, at assessment, and not when the money lands.
         * That is what stops one fee appearing on two bills: a business filing a
         * second renewal before paying the first would otherwise be shown the
         * same June sanitary fee twice, and whichever bill was paid, the other
         * would still be claiming it.
         */
        /*
         * ── This filing's OWN lateness ───────────────────────────────────
         *
         * A business permit's term ends on 20 January (`RenewalSeason`), so
         * a renewal filed on the 21st is late and Secs. 8A.04/8A.05 attach.
         * Client, 1 October 2026: *"Day after 20 January."*
         *
         * Charged on the WHOLE assessment — business tax and every
         * regulatory and permit line on this bill. Client, same date:
         * *"The whole assessment."* That is also the plain reading of Sec.
         * 8A.04's "amount due", and the tax is the largest thing being paid
         * late, so excluding it would leave the surcharge charging a
         * fraction of what was actually owed.
         *
         * Computed BEFORE the sweep, and so on this filing's own lines
         * only. The deferred rows coming in below already carry a penalty
         * frozen at their own filing; surcharging them again here would
         * charge one late sanitary permit twice — once for being renewed
         * late in June, once for arriving on a January bill that was
         * itself late — which is two penalties for one default.
         *
         * The prior permit is the BUSINESS one: a renewal carrying five
         * clearances alongside it is late or not by the business permit's
         * term, which is the one anchored to the season.
         */
        $outcome = $app->permitTypes
            ->firstWhere(fn (PermitType $pt) => $pt->code === PermitType::OUTCOME_CODE);

        if ($outcome !== null && $total > 0.0) {
            $own = $this->latePenaltyFor($app, $this->priorPermitFor($app, $outcome), $total);

            if ($own['surcharge'] > 0.0) {
                $items[] = [
                    'label' => 'Surcharge for late renewal (25%, Sec. 8A.04)',
                    'amount' => $own['surcharge'],
                ];
                $total = round($total + $own['surcharge'], 2);
            }
            if ($own['interest'] > 0.0) {
                $items[] = [
                    /*
                     * The month count is printed. An applicant handed an
                     * interest line with no period on it cannot check it,
                     * and 8A.05 caps the count at 36 — a bill that has hit
                     * the cap should say so rather than look arbitrary.
                     */
                    'label' => 'Interest on late renewal (2%/month \u00d7 '
                        .$own['months_counted'].', Sec. 8A.05)',
                    'amount' => $own['interest'],
                ];
                $total = round($total + $own['interest'], 2);
            }
        }

        $swept = $this->sweepDeferredFees($app);
        foreach ($swept as $fee) {
            $items[] = [
                /*
                 * The rows own description when it has one, so an amendment
                 * does not print as a second business-permit fee beside the
                 * real one. Falls back to the permit type, which is the right
                 * answer for every clearance row and always was.
                 */
                'label' => ($fee->description ?? $fee->permitType?->name.' fee')
                    .' ('.$fee->incurred_at->format('M Y').', unbilled until now)',
                'amount' => (float) $fee->amount,
            ];
            $total = round($total + (float) $fee->amount, 2);

            /*
             * The penalty this row was already carrying, as its own line
             * under the fee it belongs to.
             *
             * Read off the row, never recomputed: it was frozen at the late
             * renewal's filing date (see `latePenaltyFor`), and re-deriving
             * it here would make the figure depend on when BPLO happened to
             * draw the bill.
             *
             * Its own line rather than folded into the fee, so the sanitary
             * permit still reads at the ordinance's price and the penalty is
             * visible as a penalty. An applicant who is being charged extra
             * should be able to see what for and dispute it.
             */
            $penalty = round((float) $fee->surcharge + (float) $fee->interest, 2);
            if ($penalty > 0.0) {
                $items[] = [
                    'label' => 'Late surcharge and interest \u2014 '
                        .($fee->permitType?->name ?? 'permit')
                        .' ('.$fee->months_late.' month'
                        .($fee->months_late === 1 ? '' : 's').' late)',
                    'amount' => $penalty,
                ];
                $total = round($total + $penalty, 2);
            }
        }

        return FeeAssessment::updateOrCreate(
            ['application_id' => $app->id],
            ['line_items' => $items, 'total_amount' => $total]
        );
    }

    /**
     * Claim this business's unbilled permit fees for the filing about to bill.
     *
     * @return Collection<int, UnbilledPermitFee>
     */
    private function sweepDeferredFees(Application $app): Collection
    {
        $app->loadMissing('permitTypes');

        $carriesBusinessPermit = $app->permitTypes
            ->contains(fn (PermitType $pt) => $pt->code === PermitType::OUTCOME_CODE);

        if (! $carriesBusinessPermit || $app->business_id === null) {
            return collect();
        }

        /*
         * `unclaimed`, not `outstanding`. A fee already sitting on another
         * filing's bill belongs to that filing until it is paid or that filing
         * is cancelled — see the scopes on the model for why those are two
         * different questions.
         *
         * Re-assessing the SAME filing re-claims its own rows, which is why the
         * update below is idempotent on `billed_on_application_id` and why this
         * reads back what it claimed rather than trusting the caller's copy.
         */
        UnbilledPermitFee::query()
            ->where('business_id', $app->business_id)
            ->unclaimed()
            ->update(['billed_on_application_id' => $app->id]);

        return UnbilledPermitFee::query()
            ->with('permitType')
            ->where('billed_on_application_id', $app->id)
            ->outstanding()
            ->orderBy('incurred_at')
            ->get();
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

            /*
             * ── An AMENDMENT is not priced by the permit schedule ────────────
             *
             * `assessFees` walks the revenue-code rules for the permits a filing
             * carries, and an amendment carries the business permit — so it
             * quoted the WHOLE ANNUAL PERMIT again. Measured before this:
             * ₱6,425 for a floor-area correction, of which ₱6,050 was the
             * Mayor's Permit fee and ₱220 business plates.
             *
             * No assessment is raised at all rather than a wrong one. A Tax
             * Order of Payment is a figure an applicant pays, so a confidently
             * wrong number is worse than none: nobody queries a bill the system
             * produced. What an amendment actually costs has not been given to
             * us, and when it is, it belongs in its own fee rule rather than in
             * the schedule that prices permits — see
             * docs/amendment-2026-09-19.md.
             */
            if ($app->application_type !== ApplicationType::Amendment) {
                $this->assessFees($app);
            }

            /*
             * ── Who is waiting on it, and BPLO is not always the answer ──────
             *
             * A clearance-only renewal has no main form for BPLO to read and no
             * bill for BPLO to raise. Client's decision, 17 September 2026:
             * *"its office alone, BPLO never sees it."* So it is routed to
             * nobody here — the issuing office is routed by `startClearance`
             * when the applicant applies or hands in a copy, which is the same
             * one-office-at-a-time rule the other five already follow.
             *
             * Routing BPLO anyway would have put a queue item in front of an
             * office with nothing to do on it, and `approveMainForm` would then
             * have let them bill a filing that must defer — which is the bug
             * the guard there now refuses.
             *
             * The note the applicant reads changes with it. "Waiting for BPLO to
             * review the form" is false on such a filing, and a status line that
             * names the wrong office is worse than a vague one: it tells them
             * who to ring.
             */
            /*
             * ── "Defers payment" and "BPLO is not involved" came apart ───────
             *
             * `defersPayment()` was the test for both, and that held while the
             * only deferring filing was a clearance-only renewal. An AMENDMENT
             * now defers too (client, 19 September 2026: *"All amendment
             * payments will reflect when a business permit is renewed"*) — and
             * BPLO is the office that reads it. Routing off the deferral alone
             * would have left an amendment in nobody's queue, waiting for an
             * office that was never told.
             *
             * So the question asked here is the one that was always meant:
             * whose desk is this on. A clearance-only renewal is its issuing
             * office's, routed by `startClearance` when the applicant applies.
             * Everything else — new, amendment, business-permit renewal — is
             * BPLO's.
             */
            $deferred = $app->defersPayment();
            $bploReads = $app->application_type !== ApplicationType::Renewal
                || ! $deferred;

            $this->transition(
                $app,
                ApplicationStatus::ForApproval,
                $bploReads
                    ? 'Submitted. Waiting for BPLO to review the form.'
                    : 'Submitted. Apply for the permit below and its office will review it.',
            );

            if ($bploReads) {
                $this->routeTo($app, $this->bploDepartmentId());
            }

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
        // Suspended or blacklisted: nothing moves toward a permit (refuseWhileOnHold).
        $this->refuseWhileOnHold($app->business);

        if ($app->status !== ApplicationStatus::ForApproval) {
            throw ValidationException::withMessages([
                'status' => ['Only an application that is For Approval can be approved by BPLO. This one is '.($app->status?->label() ?? 'in no state').'.'],
            ]);
        }

        /*
         * ── BPLO has no say in a filing that bills nothing ───────────────────
         *
         * The status test above is not enough, and the gap was a money bug. A
         * clearance-only renewal sits at ForApproval like any other filing, so
         * BPLO could approve it — and this method's whole job is to raise the
         * bill. That would charge in June for a permit the client's rule says
         * is collected in January: *"The payment for each permit will also
         * happen ONLY WHEN a business permit was renewed on January."*
         *
         * Such a filing is the issuing office's alone (client, 17 September
         * 2026: *"its office alone, BPLO never sees it"*), which is also why
         * `submit()` no longer routes BPLO a queue item for one.
         *
         * Phrased for the officer who somehow reached it — a stale tab, a
         * pasted URL — rather than as an internal assertion, because that is
         * who will read it.
         */
        /*
         * ── An AMENDMENT is BPLO's, and defers ───────────────────────────────
         *
         * It reaches this method like any other filing BPLO reads, and it must
         * not fall into the refusal below: BPLO genuinely decides it. But there
         * is nothing to bill either, so this is not a billing act at all.
         *
         * BPLO's single approval completes the whole thing — the changes are
         * applied, the business permit is reissued with the amended details and
         * the fee is recorded against January. One act, because with no payment
         * to wait for there is nothing to put between two of them.
         */
        if ($app->application_type === ApplicationType::Amendment) {
            $this->approveAmendment($app, $remarks);

            return;
        }

        if ($app->defersPayment()) {
            throw ValidationException::withMessages([
                'status' => [
                    'This renewal does not carry the business permit, so there is nothing for BPLO to bill. '
                    .'It is issued by the office that grants the permit, and its fee is collected on the next business permit renewal.',
                ],
            ]);
        }

        /*
         * The processing-category gate stood here until 27 September 2026.
         *
         * It refused to approve a filing nobody had classified, because
         * `Ra11032::tierFor()` was our guess and RA 11032 leaves the
         * classification to the LGU. Malabon has published theirs — new and
         * renewal business permits are Simple — so the tier is now read from
         * the charter and can never be unknown. There is nothing left to
         * confirm, and a gate on a question with one answer is a step that
         * only ever costs an officer a click.
         */

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

            $this->raiseTinRequirement($app);
        });
    }

    /** The system's key for the automatic "you left your TIN blank" requirement. */
    public const TIN_REQUIREMENT_KEY = 'business.tin';

    /**
     * Ask for the TIN the applicant did not give, without holding anything up.
     *
     * Raised inside the approval transaction, so a filing cannot reach
     * Pending Payment carrying a blank TIN and no requirement to fix it —
     * the two facts are written together or not at all.
     *
     * `requested_by_user_id` is null on purpose. The column is nullable and
     * this requirement has no author: attributing it to whichever officer
     * pressed Approve would put a person's name on a sentence they did not
     * write and a judgement they did not make. The DEPARTMENT is BPLO's,
     * because BPLO is the office that must close it.
     */
    private function raiseTinRequirement(Application $app): void
    {
        $business = $app->business;
        if ($business === null || trim((string) $business->tin) !== '') {
            return;
        }

        /*
         * Never twice. A renewal is a fresh filing and gets its own, but one
         * filing returned and re-approved must not stack a second copy of
         * the same question on the applicant.
         */
        $exists = OfficerRequest::where('application_id', $app->id)
            ->where('system_key', self::TIN_REQUIREMENT_KEY)
            ->exists();
        if ($exists) {
            return;
        }

        $req = OfficerRequest::create([
            'application_id' => $app->id,
            'requested_by_user_id' => null,
            'department_id' => $this->bploDepartmentId(),
            'title' => 'Tax Identification Number (TIN)',
            'description' => 'You left the Tax Identification Number (TIN) blank on your application form. '
                .'Type it in your reply below — there is no document to attach. '
                .'It is the TIN of the owner or the registered entity, as printed on your BIR papers, '
                .'like 123-456-789-000.',
            'request_type' => 'message',
            'system_key' => self::TIN_REQUIREMENT_KEY,
            'status' => OfficerRequestStatus::Pending,
        ]);

        Audit::log('request.raised_by_system', $req, [
            'application_id' => $app->id,
            'business_id' => $business->id,
            'system_key' => self::TIN_REQUIREMENT_KEY,
        ]);

        /*
         * ── And the applicant is actually told ───────────────────────────
         *
         * Added 27 September 2026, the same day the requirement itself was,
         * after the client asked where "Other Requirements" lives. It was
         * raised, stored and tested end to end — and notified nobody, so it
         * sat in the table waiting for someone who had no way to know it was
         * there. `OfficerRequestController::store` sends this for a
         * hand-written requirement; a system-raised one owes the applicant
         * exactly the same word.
         *
         * The wording is the officer-raised one's, unchanged. "An officer
         * requested" is true enough — BPLO's approval is what raised it and
         * BPLO is the office that will close it — and a second near-identical
         * sentence for the system's own case would be two spellings of one
         * event in the applicant's notification list.
         */
        if ($app->applicant) {
            $this->notify->requestCreated($req->load('application'), $app->applicant);
        }
    }

    /** BPLO returns the main form for revision. for_approval → returned. */
    /**
     * @param  string|null  $target  Which field the applicant must fix, as a
     *                               code the system owns. Null is a perfectly good return — the prose is
     *                               never parsed to derive one, the same rule `returnClearance` follows.
     */
    /**
     * @param  array<string, string>  $notes  One remark per returned field,
     *                                        keyed by the same `form:` code as $target. Client, 27 September
     *                                        2026: *"Allow to put 1 comment/remark per field selected, not just 1
     *                                        remark for all fields."* Empty is still valid — a return that names
     *                                        no fields carries prose alone, as every return did before the
     *                                        picker existed.
     */
    public function returnMainForm(
        Application $app,
        string $remarks,
        ?string $target = null,
        array $notes = [],
    ): void {
        DB::transaction(function () use ($app, $remarks, $target, $notes) {
            /*
             * REPLACED on every return, including with null — `returnClearance`
             * says why at length: a stale pointer from a previous round flags a
             * field this return is not about, so the applicant fixes the wrong
             * thing and is returned twice.
             */
            $app->assignments()
                ->where('department_id', $this->bploDepartmentId())
                ->update([
                    'status' => AssignmentStatus::Returned->value,
                    'remarks' => $remarks,
                    'remarks_target' => $target,
                ]);

            /*
             * The notes are replaced as a SET, on the pointer's own reasoning
             * one comment up: a note left over from a previous round sits
             * under a field this round is not about and tells the applicant
             * to fix something nobody asked about.
             */
            /*
             * `whereNull('permit_type_id')` — only the MAIN FORM's notes.
             * The five offices write into this table too since 30
             * September 2026, scoped to their permit, and an unscoped
             * clear here would delete CHO's instructions every time BPLO
             * returned the form.
             */
            ApplicationReturnNote::where('application_id', $app->id)
                ->whereNull('permit_type_id')
                ->delete();
            $this->writeReturnNotes($app->id, null, $notes);

            /*
             * ── What each named field says, as the officer saw it ──────
             *
             * Compared once at resubmission to record what the applicant
             * changed. Captured HERE rather than diffed on save because
             * `PUT /applications/{id}` is the wizard's autosave, and
             * diffing there would write a correction row per keystroke
             * batch instead of one per field per round.
             *
             * This is the only record a SECTION target ever gets. Scalars
             * have the correction card, which writes its own rows as it
             * saves; the wizard fields had nothing, so a filing fixed
             * there came back looking untouched.
             *
             * `at` is stored with the values so the comparison can tell
             * which targets the card has already accounted for.
             */
            $app->forceFill([
                'returned_values' => [
                    'at' => now()->toISOString(),
                    'values' => ReturnTargets::snapshot(
                        $app->load(['business.address.barangay', 'business.owners', 'business.lines']),
                        ReturnTargets::parse($target),
                    ),
                ],
            ])->save();

            $this->transition($app, ApplicationStatus::Returned, $remarks);
        });
    }

    /**
     * Change what an open return asks for, without returning again.
     *
     * The officer has already sent this filing back and has since noticed
     * something else, or worded the remark badly. A second return is not
     * legal — `ApplicationStatus::Returned` goes only forward — and it
     * should not be: the filing is with the applicant, and bouncing it
     * would interrupt a repair already under way.
     *
     * So this REPLACES the instruction in place. Nothing transitions,
     * nothing is added to the history, and the applicant's next look at
     * the correction dialog simply shows the amended list.
     *
     * Refuses a filing that is not returned, which is the whole guard: on
     * anything else the office should be pressing Return, and silently
     * rewriting an approved filing's pointer would be a different bug.
     *
     * @param  array<string, string>  $notes
     */
    public function amendMainFormReturn(
        Application $app,
        string $remarks,
        ?string $target = null,
        array $notes = [],
    ): void {
        if ($app->status !== ApplicationStatus::Returned) {
            throw ValidationException::withMessages([
                'status' => ['This filing is not with the applicant, so there is no return to change.'],
            ]);
        }

        DB::transaction(function () use ($app, $remarks, $target, $notes) {
            /*
             * The same writes `returnMainForm` makes, minus the transition.
             * Every assignment on the filing, because that is where the
             * pointer lives and the applicant's screen reads all of them.
             */
            ApplicationAssignment::where('application_id', $app->id)
                ->where('status', AssignmentStatus::Returned->value)
                ->update([
                    'remarks' => $remarks,
                    'remarks_target' => $target,
                ]);

            ApplicationReturnNote::where('application_id', $app->id)
                ->whereNull('permit_type_id')
                ->delete();
            $this->writeReturnNotes($app->id, null, $notes);

            /*
             * Re-snapshotted against the NEW pointer. The snapshot is what
             * the resubmission is compared with to report what changed, so
             * keeping the old one would compare a newly-named field against
             * nothing and call every one of them unchanged.
             */
            $app->forceFill([
                'returned_values' => [
                    'at' => now()->toISOString(),
                    'values' => ReturnTargets::snapshot(
                        $app->load(['business.address.barangay', 'business.owners', 'business.lines']),
                        ReturnTargets::parse($target),
                    ),
                ],
            ])->save();
        });

        /*
         * Told, because otherwise nobody is. A return notifies and a
         * refusal notifies; changing what either of them asked for used to
         * be silent, so an office could add a second field and the one
         * person who has to act on it would find out only by reopening a
         * dialog they believe they have already answered.
         *
         * Outside the transaction, like every other notification here: a
         * message that cannot be unsent has no business inside something
         * that can be rolled back.
         */
        $this->notify->applicationStatus(
            $app,
            $app->status,
            'BPLO changed what needs correcting: '.$remarks,
        );
    }

    /**
     * Change what an office's open return asks for, without returning again.
     *
     * `amendMainFormReturn` for a clearance. Same rule and same reason:
     * `ClearanceStatus::Returned` goes only to ForApproval, so the office
     * cannot send back a sheet the applicant is already holding, and this
     * corrects the instruction in place instead.
     *
     * @param  array<string, string>  $notes
     */
    public function amendClearanceReturn(
        ApplicationPermitType $row,
        string $remarks,
        ?string $target = null,
        array $notes = [],
    ): void {
        /*
         * Returned OR Rejected. Both are with the applicant, and a refusal
         * is the one that most needs correcting: it suspends the business
         * permit while it stands, so an officer who ticked the wrong row or
         * wrote an unusable remedy is holding a trading business shut over
         * a mistake they cannot take back.
         */
        if (! in_array($row->status, [ClearanceStatus::Returned, ClearanceStatus::Rejected], true)) {
            throw ValidationException::withMessages([
                'status' => ['This permit is not with the applicant, so there is nothing to change.'],
            ]);
        }

        DB::transaction(function () use ($row, $remarks, $target, $notes) {
            /*
             * `rejected_at`, `rejection_note` and `rejection_remedy` are NOT
             * touched. On a refusal the officer is correcting WHICH rows they
             * meant; the refusal itself stands, and rewriting its record from
             * an amend would lose the fact that this permit was refused —
             * which is what the office re-reading it needs most.
             */
            $row->update([
                'remarks' => $remarks,
                'remarks_target' => $target,
                /*
                 * Re-captured against the new pointer, for the reason the
                 * main form's is: `recordClearanceCorrections` diffs the
                 * resubmission against this, and a field named for the
                 * first time has nothing here to be compared with.
                 */
                'returned_state' => ClearanceSnapshot::capture(
                    $row->application,
                    $row->permitType->code,
                    ReturnTargets::parse($target),
                ),
            ]);

            ApplicationReturnNote::where('application_id', $row->application_id)
                ->where('permit_type_id', $row->permit_type_id)
                ->delete();
            $this->writeReturnNotes($row->application_id, $row->permit_type_id, $notes);
        });

        /* Told, for the reason `amendMainFormReturn` gives at length. */
        $this->notify->applicationStatus(
            $row->application,
            $row->application->status,
            $row->permitType->name.': the office changed what needs correcting — '.$remarks,
        );
    }

    /**
     * Record what the applicant changed in the wizard, against the snapshot
     * taken when the filing was returned.
     *
     * Unchanged fields are recorded too, on `corrections()`'s own reasoning:
     * *"The applicant looked at this and left it as it was" is an answer, and
     * an officer who asked about a field needs to see that rather than an
     * empty list that reads as "they ignored me".*
     */
    private function recordWizardCorrections(Application $app): void
    {
        $snapshot = $app->returned_values;
        if (! is_array($snapshot) || ! is_array($snapshot['values'] ?? null)) {
            return;
        }

        $since = $snapshot['at'] ?? null;
        $app->load(['business.address.barangay', 'business.owners', 'business.lines']);

        /*
         * Targets the correction card already wrote a row for this round.
         * Without this a scalar fixed on the card would be recorded twice —
         * once correctly by `corrections()`, once here with the same pair.
         */
        $alreadyRecorded = $since === null
            ? []
            : ApplicationCorrection::where('application_id', $app->id)
                /*
                 * Parsed, not passed as the raw ISO string. `toISOString()` gives
                 * "2026-09-29T12:00:00.000000Z" and the column holds
                 * "2026-09-29 12:00:00" — compared as text those never match, so
                 * the guard silently caught nothing and every scalar corrected on
                 * the card was recorded a second time here. Caught by
                 * ReturnedFieldsAreCorrectedTest: "2 records were found."
                 */
                ->where('created_at', '>=', Carbon::parse($since))
                ->pluck('target')
                ->all();

        foreach ($snapshot['values'] as $code => $was) {
            if (in_array($code, $alreadyRecorded, true)) {
                continue;
            }

            ApplicationCorrection::create([
                'application_id' => $app->id,
                'target' => $code,
                'old_value' => $was,
                'new_value' => ReturnTargets::displayValue($app, $code),
            ]);
        }
    }

    /**
     * Store one note per returned row, dropping the blanks.
     *
     * Shared by the main form and the offices so the two cannot drift into
     * storing the same thing differently — which is how the offices came to
     * store nothing at all.
     *
     * A blank note is skipped rather than stored empty: the officer's UI
     * already refuses to send one, so a blank arriving here is a caller
     * that did not ask for a note on that row, not an officer who left it
     * empty.
     *
     * @param  array<string, string>  $notes  keyed by target code
     */
    private function writeReturnNotes(int $applicationId, ?int $permitTypeId, array $notes): void
    {
        foreach ($notes as $code => $note) {
            $text = trim((string) $note);
            if ($text === '') {
                continue;
            }

            ApplicationReturnNote::create([
                'application_id' => $applicationId,
                'permit_type_id' => $permitTypeId,
                'target' => $code,
                'note' => $text,
            ]);
        }
    }

    /**
     * Refuse a resubmission while a ticked row is still empty.
     *
     * ── Why only the empty ones ─────────────────────────────────────────────
     *
     * The client's rule for the main form is that a returned field must be
     * answered before it can go back. The faithful version here is narrower on
     * purpose: `ClearanceStatus` allows `Returned → ForApproval` and nothing
     * else, so an office cannot approve a row it has returned. Demanding a
     * DIFFERENT file would leave an applicant whose document was right all
     * along unable to resubmit and unable to be waved through, with uploading
     * a deliberately different file as the only escape.
     *
     * An empty row has no such trap: attaching something is always possible,
     * and "you never sent this" is the case that plainly wastes a review
     * today.
     */
    /**
     * Refuse a re-application that changed nothing since the refusal.
     *
     * Re-applying reopens the sheet with every answer still on it — the
     * client's choice, and the right one, since retyping twenty fields to
     * fix one invites new errors. The cost is that resubmitting an
     * identical form is the path of least effort, and the office spends a
     * second review discovering that.
     *
     * Only after a REFUSAL. A return has `refuseEmptyReturnedRows`, which
     * is narrower on purpose — see its note — and this is the case that
     * one deliberately leaves alone: `ClearanceStatus::Rejected` goes to
     * ForApproval, so an office CAN approve a permit it refused once the
     * applicant answers, and nobody is trapped by being asked to change
     * something.
     *
     * The test is that ANYTHING moved, not that the named rows did,
     * because a refusal may name nothing: "the premises are not zoned for
     * this" is about no field in particular and might be answered by a
     * lease at another address. The weakest claim that still rules out the
     * case worth ruling out.
     */
    private function refuseUnchangedAfterRefusal(ApplicationPermitType $row): void
    {
        if ($row->status !== ClearanceStatus::Rejected) {
            return;
        }

        $before = $row->returned_state;
        /*
         * Nothing to compare — a refusal made before this snapshot existed,
         * or one whose capture failed. Let it through: refusing on absent
         * evidence would strand an applicant over a record they never had.
         */
        if (! is_array($before) || $before === []) {
            return;
        }

        $now = ClearanceSnapshot::capture(
            $row->application,
            $row->permitType->code,
            array_keys($before),
        );

        if ($now !== $before) {
            return;
        }

        throw ValidationException::withMessages([
            'requirements' => [
                'This is the same form this office refused. Change what they asked for '
                .'before sending it again — their reason and what would settle it are '
                .'on the permit.',
            ],
        ]);
    }

    private function refuseEmptyReturnedRows(ApplicationPermitType $row): void
    {
        $codes = ReturnTargets::parse($row->remarks_target);
        if ($codes === []) {
            return;
        }

        $missing = [];
        /*
         * `?? []` — `SheetRequirements::for()` returns NULL for a permit with
         * no checklist of its own, and SANITARY has none. Its docblock says
         * so; iterating it without this threw "foreach() argument must be of
         * type array|object" the moment a health officer returned a sheet.
         */
        foreach (SheetRequirements::for($row->application, $row->permitType->code) ?? [] as $checklistRow) {
            $code = $checklistRow['code'] ?? null;
            if ($code === null || ! in_array($code, $codes, true)) {
                continue;
            }

            if (($checklistRow['document'] ?? null) === null) {
                $missing[] = $checklistRow['label'];
            }
        }

        if ($missing === []) {
            return;
        }

        throw ValidationException::withMessages([
            'requirements' => [
                count($missing) === 1
                    ? 'Attach '.$missing[0].' before sending this back.'
                    : 'Attach these before sending this back: '.implode(', ', $missing).'.',
            ],
        ]);
    }

    /**
     * Refuse a sheet whose checklist is not complete.
     *
     * `blocking` is decided once, per row, by the requirement classes, and
     * read by all three of the applicant's sheet, the officer's review
     * screen and this refusal. See the note beside it in
     * `ChecklistSupport::build()` for why every row carries it now.
     *
     * The message names the rows. "A document is missing" on a list of
     * eleven sends the applicant back to hunt for which.
     */
    private function refuseIncompleteChecklist(ApplicationPermitType $row): void
    {
        $missing = [];
        /*
         * `?? []` — `SheetRequirements::for()` returns NULL for a permit
         * with no checklist of its own, and SANITARY has none.
         */
        foreach (SheetRequirements::for($row->application, $row->permitType->code) ?? [] as $item) {
            if (($item['blocking'] ?? false) === true && ($item['satisfied'] ?? false) !== true) {
                $missing[] = $item['label'];
            }
        }

        if ($missing === []) {
            return;
        }

        throw ValidationException::withMessages([
            'requirements' => [
                count($missing) === 1
                    ? 'Attach '.$missing[0].' before submitting this form.'
                    : 'Attach these before submitting this form: '.implode(', ', $missing).'.',
            ],
        ]);
    }

    /**
     * Record what the applicant changed on the rows this office asked about.
     *
     * The office half of `recordWizardCorrections`, reading
     * `ApplicationPermitType::returned_state` instead of
     * `applications.returned_values`, and writing the same
     * `ApplicationCorrection` rows scoped by `permit_type_id`.
     *
     * Unchanged rows are recorded too, for the reason `corrections()` gives:
     * "the applicant looked at this and left it as it was" is an answer, and
     * an office that asked about a row needs to see that rather than an empty
     * list which reads as having been ignored. It is also the only way the
     * office learns that a file it called wrong came back identical.
     */
    private function recordClearanceCorrections(ApplicationPermitType $row): void
    {
        $snapshot = $row->returned_state;
        if (! is_array($snapshot) || ! is_array($snapshot['values'] ?? null)) {
            return;
        }

        $now = ClearanceSnapshot::capture(
            $row->application,
            $row->permitType->code,
            array_keys($snapshot['values']),
        );

        foreach ($snapshot['values'] as $code => $was) {
            ApplicationCorrection::create([
                'application_id' => $row->application_id,
                'permit_type_id' => $row->permit_type_id,
                'target' => $code,
                'old_value' => $was,
                'new_value' => $now[$code] ?? null,
            ]);
        }
    }

    /** B: resubmit a returned form. returned → for_approval. */
    public function resubmit(Application $app): void
    {
        /*
         * Read BEFORE the assignments are cleared. `returned_by` is the
         * officer holding the question, and the update below puts every
         * returned assignment back to Pending — after it there is nothing
         * left saying who sent this filing back.
         */
        $returnedBy = $app->assignments()
            ->where('status', AssignmentStatus::Returned->value)
            ->with('officer')
            ->first()?->officer;

        DB::transaction(function () use ($app) {
            /*
             * BEFORE the assignments are cleared and before the snapshot is
             * dropped: this is the only moment both halves of the comparison
             * exist. Inside the transaction, so a filing cannot reach the
             * office as resubmitted with no record of what changed.
             */
            $this->recordWizardCorrections($app);

            $app->assignments()
                ->where('status', AssignmentStatus::Returned->value)
                ->update(['status' => AssignmentStatus::Pending->value, 'remarks' => null]);

            /*
             * The round is over. `returned_values` is the state of ONE open
             * return, not history — the history is `application_corrections`
             * — and a snapshot left behind would be compared again on the
             * next resubmission against values nobody was asked about.
             */
            $app->forceFill(['returned_values' => null])->save();

            $this->transition($app, ApplicationStatus::ForApproval, 'Applicant resubmitted revisions.');
        });

        /*
         * ── And the office is told ──────────────────────────────────────
         *
         * Outside the transaction, like every other notification here: a
         * push that fails must not roll back a resubmission the applicant
         * has already been shown as done.
         *
         * Falls back to whoever holds BPLO's assignment when the returning
         * officer's account has gone — a notice that disappears with a
         * staff change is the failure this exists to prevent.
         */
        /*
         * The returning officer if the case was claimed; otherwise everyone
         * in the office that holds it.
         *
         * `officer_user_id` is null until somebody takes the case, and a
         * returned filing nobody has claimed is precisely the one that comes
         * back unnoticed — so falling back to a second nullable officer, as
         * the first version did, skipped the notification exactly when it
         * was most needed. The office is the honest last answer: it is the
         * same set of people the queue would show the filing to.
         */
        $recipients = $returnedBy !== null
            ? collect([$returnedBy])
            : User::where('department_id', $this->bploDepartmentId())->get();

        $corrected = ApplicationCorrection::where('application_id', $app->id)->count();
        $fresh = $app->fresh();
        foreach ($recipients as $recipient) {
            $this->notify->filingResubmitted($fresh, $recipient, $corrected);
        }
    }

    /**
     * Terminal rejection of the whole filing. Reachable from any live status.
     *
     * The other offices' open reviews are closed by `transition()` on the way
     * to Rejected (closeOpenReviews), in the same transaction as the decision,
     * so a filing cannot be rejected and still sit in CHO's queue.
     */
    public function rejectApplication(Application $app, string $reason): void
    {
        DB::transaction(function () use ($app, $reason) {
            $app->update(['rejection_reason' => $reason, 'decided_at' => now()]);
            $this->transition($app, ApplicationStatus::Rejected, $reason);

            /*
             * ── The certificate goes with the filing ─────────────────────────
             *
             * This method wrote to the application row and nothing else, which
             * was right while the permit was minted at the very end. Since the
             * release moved to payment on 24 September 2026 it was not: BPLO
             * could reject a paid filing and leave the Business Permit Active and
             * still answering yes on the public /verify page, so the applicant
             * kept trading on a certificate attached to a refused application.
             *
             * Suspended rather than revoked — see the note above the method.
             *
             * Only an ACTIVE one, the same rule `suspendOutcomePermit` follows: an
             * expired or superseded certificate is not something anyone can trade
             * on, and overwriting its status would lose how its term actually
             * ended.
             *
             * Inside the same transaction as the status: if the suspension
             * fails, the rejection rolls back with it and BPLO sees the error,
             * so a rejected filing is never left holding an Active permit.
             */
            $permit = $this->outcomePermitFor($app);
            if ($permit !== null && $permit->status === PermitStatus::Active) {
                $permit->update(['status' => PermitStatus::Suspended]);

                /*
                 * Its own audit action, not `permit.suspended`. That one records
                 * WHICH office refused WHICH clearance, and nothing was refused
                 * here — BPLO ended the filing. Reusing it would put a cause in
                 * the trail that did not happen.
                 */
                Audit::log('permit.suspended_on_rejection', $permit, [
                    'application_id' => $app->id,
                    'business_id' => $app->business_id,
                    'reason' => $reason,
                ]);
            }
        });
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

        /*
         * ── Money that lands while the business is suspended is held ───────
         *
         * An online order opened before the super admin suspended or
         * blacklisted the business can still be paid after it, and the payment
         * is real: it stays Completed. The filing does not move and nothing is
         * issued (Ken's decision after the October 2026 scenario run — the
         * callback had been minting an Active permit for a suspended business,
         * owner-pay 19). Returning rather than refusing, because the money has
         * already arrived and refusing it would lose the record of it.
         *
         * `releaseHeldFilings` brings the filing back through this method when
         * the business is put back, so the owner never pays twice.
         */
        if ($this->onHold($app)) {
            Audit::log('payment.held', $payment, [
                'application_id' => $app->id,
                'business_id' => $app->business_id,
            ]);

            return;
        }

        /*
         * ── A renewal has nothing to gather, so it skips the gathering ────────
         *
         * Client's decision, 17 September 2026: *"For the business permit, there
         * should no longer be Awaiting Other Permits status because the
         * applicant may already have valid other permit that he/she can submit
         * in the Upload/Submit button."*
         *
         * A renewal's clearances are certificates the offices have ALREADY
         * issued, so there is no gathering to do — only uploading, and then
         * BPLO reading what was uploaded.
         *
         * The uploads happen AFTER this, not before: a renewal carrying the
         * business permit is billed, so `defersPayment()` is false and the
         * clearance stage stays gated on payment like any other. So the filing
         * arrives at Final Approval with the copies still to come, and BPLO
         * waits for them — `outstandingClearances` counts a permit with no mode
         * as outstanding, and only an UPLOADED one as satisfied. (An earlier
         * version of this note claimed the evidence was already in hand by now,
         * which is not how the gate works.)
         *
         * Sending such a filing to AwaitingOtherPermits would
         * park it in a stage named for waiting, waiting for nothing, until
         * `refreshReadiness` noticed and moved it on — which is a status the
         * applicant would watch flash past and, worse, a queue tab an officer
         * would see it sit in.
         *
         * An AMENDMENT keeps the new-filing path. `attachRequiredPermitTypes`
         * leaves amendments on it deliberately ("the client has said they will
         * deal with it separately"), and inventing a route for them here would
         * be deciding that separate question by accident.
         */
        /*
         * The deferred fees this bill was carrying are now collected.
         *
         * `billed_at` and not the claim: the claim was taken at assessment so
         * the fee could not be swept twice, and this is the separate fact that
         * the money arrived. A fee stamped here stops appearing in the
         * business's arrears; one only claimed still does, because from the
         * LGU's side it is equally unpaid.
         */
        UnbilledPermitFee::query()
            ->where('billed_on_application_id', $app->id)
            ->outstanding()
            ->update(['billed_at' => now()]);

        /*
         * ── Where the money lands you depends on what you filed ──────────────
         *
         * An AMENDMENT goes back to BPLO. Its last step is on the LGU's own
         * paper, in as many words: *"AFTER PAYMENT, PLEASE RETURN THIS FORM
         * AND OTHER REQUIREMENTS TO THE BPLO WINDOW FOR COMPLETION OF
         * PROCESS."* The counter completes it, and that is also where the
         * register is changed — a real act rather than a rubber stamp, and
         * not a candidate for the automatic issuance new filings get.
         *
         * ── A RENEWAL joined the NEW filing's path on 3 October 2026 ─────────
         *
         * It went back to BPLO too, for one reason: *"a renewal's certificates
         * are copies BPLO has to read"* (§5 of docs/renewal-2026-09-17.md).
         * There are no such copies now — the client removed the upload on a
         * renewal: *"an admin verifying an uploaded other permit will be
         * useless if the system already tells them whether they are still
         * valid or not."* With nothing to read, the stage was a wait with no
         * work in it, and the RA 11032 clock ran through it.
         *
         * This is the FOURTH door to For Final Approval and the one that
         * would have been missed: `refreshReadiness` stopped sending renewals
         * there, but a renewal never reached `refreshReadiness` — it arrived
         * at the stage straight from payment.
         *
         * Two consequences, both intended and both the new filing's existing
         * behaviour rather than anything invented here:
         *
         *  - the renewed Mayor's Permit is released AT PAYMENT, by
         *    `releaseOutcomePermit` below. That is the LGU's rule of
         *    24 September 2026 — *"after payment, business permit is already
         *    released, but can be suspended if the other permits applied to
         *    were rejected"* — which was written about business permits and
         *    had been applied to new filings alone. A business renewing in
         *    January can trade on it while a clearance catches up.
         *  - the filing waits at AwaitingOtherPermits for the permits it is
         *    actually renewing. A renewal carries ONLY the ticked ones (see
         *    `attachRequiredPermitTypes`), so one renewing nothing else has
         *    nothing to wait for — `refreshReadiness` below closes it in the
         *    same request, and the client's 17 September ask that a renewal
         *    *"should no longer be Awaiting Other Permits"* still holds for
         *    exactly the filings it was asked about.
         */
        $backToBplo = $app->application_type === ApplicationType::Amendment;

        if ($backToBplo) {
            $this->transition(
                $app,
                ApplicationStatus::ForFinalApproval,
                'Payment received. Waiting for BPLO’s final approval.',
            );

            return;
        }

        /*
         * ── Is there anything to gather? ────────────────────────────────────
         *
         * Asked BEFORE the filing is told where it is going, so one with
         * nothing to wait for never records a wait. A status held for a
         * millisecond is still a status an officer's queue could catch the
         * filing in, and still a line in the history an auditor has to
         * explain — which is the standard `RenewalSkipsGatheringTest` has
         * held this path to since 17 September 2026.
         *
         * It never arose while this was the new filing's path alone: one of
         * those always carries all five clearances. A renewal carries only
         * the permits it is renewing, and the common January filing renews
         * the Mayor's Permit by itself.
         *
         * Officer requests are not counted, unlike in `refreshReadiness`:
         * nobody has reviewed this filing yet, so there is no office that
         * could have asked it for anything.
         */
        $app->load('permitTypes');
        $nothingToGather = $this->outstandingClearances($app)->isEmpty();

        $this->transition(
            $app,
            $nothingToGather ? ApplicationStatus::Approved : ApplicationStatus::AwaitingOtherPermits,
            $nothingToGather
                ? 'Payment received. Your Business Permit has been released, and this filing '
                    .'carries no other permit, so it is closed.'
                : 'Payment received. Your Business Permit has been released. '
                    .'You can now apply for the other permits.',
        );

        $this->releaseOutcomePermit($app);

        if ($nothingToGather) {
            $app->update(['decided_at' => now()]);
            $this->notify->permitsIssued($app->fresh());
        }
    }

    /**
     * Mint the Business Permit the moment the money lands.
     *
     * ── The LGU moved the release, 24 September 2026 ─────────────────────
     *
     * *"After payment, business permit is already released, but can be
     * suspended if the other permits applied to were rejected."*
     *
     * It used to be minted by `approveOverall()` at the very end, on the
     * strength of all five clearances. That inverted the dependency the LGU
     * actually operates: the business permit is the thing being applied for
     * and the clearances are conditions ATTACHED to it, so the applicant who
     * has paid holds the permit and risks losing it, rather than waiting on
     * five offices before they may trade at all.
     *
     * NEW filings only, and the caller decides that — the same `$backToBplo`
     * split that routes the filing. A renewal and an amendment both go back
     * to BPLO after payment because there is a human act left in each (BPLO
     * reads uploaded certificates; the counter completes the amendment on the
     * LGU's own paper), and issuing ahead of that act would be issuing over
     * the decision, not before it.
     *
     * ── Why the pivot is forced rather than transitioned ─────────────────
     *
     * `ClearanceStatus::ForApproval` may legally become ForInspection,
     * Returned or Rejected — not Approved. That table is about the five
     * clearances, each of which is read and then visited; the BUSINESS row
     * has no visit and never did. `approveOverall()` has always forced the
     * same row for the same reason, and this is the same act moved earlier,
     * so it forces it the same way rather than widening a table that would
     * then let a sanitary permit skip its inspection.
     *
     * Idempotent at both levels: the status is only written when the row is
     * not already Approved, and `issuePermitFor()` is a firstOrCreate on
     * (application, permit type). A duplicate payment webhook cannot mint a
     * second certificate — though `onPaymentCompleted` returns early on a
     * filing that is no longer PendingPayment, so it should not get here.
     *
     * A filing with no BUSINESS row is left alone. A renewal may carry any
     * subset of the permits and need not include this one; that path does not
     * reach here today, and returning quietly is the right answer if it ever
     * does.
     */
    private function releaseOutcomePermit(Application $app): void
    {
        $row = $this->pivotFor($app, PermitType::OUTCOME_CODE);
        if ($row === null || $row->status === ClearanceStatus::Approved) {
            return;
        }

        DB::transaction(function () use ($app, $row) {
            $row->update(['decided_at' => now()]);
            $row->forceFill(['status' => ClearanceStatus::Approved])->save();

            Audit::log('clearance.status_changed', $row, [
                'application_id' => $app->id,
                'permit_type_id' => $row->permit_type_id,
                'from' => ClearanceStatus::ForApproval->value,
                'to' => ClearanceStatus::Approved->value,
                'note' => 'Released on payment.',
            ]);

            $this->issuePermitFor($app, $row->permitType);
        });

        $this->notify->applicationStatus(
            $app,
            $app->status,
            'Your Business Permit has been released. The other permits on this '
            .'application are still being processed — if one of them is rejected, '
            .'this permit will be suspended until it is settled.',
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
        /*
         * Paid, OR nothing to pay. A clearance-only renewal is never billed —
         * its fee is swept onto the next business-permit renewal — so gating it
         * on payment refused for ever the one permit the filing existed to
         * renew. See `Application::defersPayment`, which both this and
         * `ClearanceService::isUnlocked` ask so the two cannot drift.
         */
        if (! $app->status?->isPaid() && ! $app->defersPayment()) {
            throw ValidationException::withMessages([
                'status' => ['The other permits open once this application is paid.'],
            ]);
        }

        if (! in_array($mode, [ApplicationPermitType::MODE_APPLY, ApplicationPermitType::MODE_UPLOAD], true)) {
            throw ValidationException::withMessages([
                'mode' => ['A permit is either applied for or handed in as a copy you already hold.'],
            ]);
        }

        /*
         * ── A new business holds no permits ──────────────────────────
         *
         * The LGU's rule, relayed by the client on 29 September 2026: a
         * business cannot already hold these before it applies to BPLO,
         * and one business may not hand in another's certificate.
         *
         * Nothing here can tell whose certificate a file is — it is an
         * image against a permit row, checked by an officer reading the
         * name on it — so the only place to enforce the rule is the
         * choice itself.
         *
         * Refused rather than quietly turned into `apply`: the applicant
         * asked for something the city does not allow, and silently doing
         * a different thing would leave them believing a copy had been
         * accepted.
         *
         * ── And a RENEWAL may no longer hand one in either ───────────
         *
         * Client, 3 October 2026: *"an admin verifying an uploaded other
         * permit will be useless if the system already tells them whether
         * they are still valid or not."*
         *
         * Which is the case. A renewal attaches only the permits the
         * applicant TICKED in the entry dialog (see
         * `attachRequiredPermitTypes`), so a certificate still in date is
         * never on the filing at all — BizTrack holds it, says so on the
         * row, and asks nothing. What could be uploaded was therefore a
         * copy of a permit the applicant had just said needs renewing:
         * an expired one, read by BPLO to confirm something the register
         * already knew.
         *
         * The case the mode was built for — a current permit issued
         * outside BizTrack — is the one the client ruled out on 18
         * September 2026: no permit is ever renewed on a manual system.
         * It has had no rows in the register since.
         *
         * This is the chokepoint for both doors: `ClearanceService::apply`
         * and `submitHeld` are the only callers, and the upload endpoint
         * reaches the second. Guarding here rather than in the controller
         * is what stops the two drifting.
         *
         * An AMENDMENT keeps the mode, for the reason
         * `attachRequiredPermitTypes` leaves it on the new-filing path:
         * its shape is an open question the client will take separately,
         * and answering it here by omission would be answering it.
         */
        if ($mode === ApplicationPermitType::MODE_UPLOAD
            && in_array($app->application_type, [ApplicationType::New, ApplicationType::Renewal], true)) {
            throw ValidationException::withMessages([
                'mode' => [
                    $app->application_type === ApplicationType::Renewal
                        ? 'BizTrack already holds this permit and knows whether it is valid. Apply to renew it instead.'
                        : 'A new business has no permits to hand in yet. Apply for this one instead.',
                ],
            ]);
        }

        return DB::transaction(function () use ($app, $type, $mode) {
            $row = $this->pivotFor($app, $type->code);
            if ($row === null) {
                $app->permitTypes()->attach($type->id, ['status' => ClearanceStatus::NotStarted->value]);
                $row = $this->pivotFor($app, $type->code);
            }

            /*
             * `'rejection_reason' => null` was cleared here too, and the column
             * is gone (17 September 2026). Nothing replaces it: choosing a route
             * is not a response to anything an office said, so there is no note
             * to clear. The RETURN note and its pointer are deliberately left
             * standing — the applicant switching from Apply to Upload has not
             * answered what CPDO asked for, and wiping the instruction would be
             * the fastest way to lose it.
             */
            $row->update(['mode' => $mode]);

            /*
             * ── Applying is not submitting, when there is a form to fill ──────
             *
             * This moved the permit straight to ForApproval and routed the
             * office, on the press of Apply — and wrote the history note
             * "Applicant completed the office form" while doing it. The
             * applicant had completed nothing: Apply's whole job is to OPEN the
             * form.
             *
             * What that cost, twice, from both ends of the same filing. The
             * applicant's Track page showed "For Approval" on two clearances
             * they had not filled in ("I still haven't submitted any
             * applications yet the status says it is For Approval"), and CENRO
             * opened a queue row with no answers on it at all ("I still can't
             * view the application fields"). One wrong claim, read from two
             * seats.
             *
             * The rule now, settled with the client on 9 September 2026: you
             * submit a clearance by giving the office something to read.
             *
             *  - MODE_UPLOAD submits immediately. The file IS the answer, and
             *    it is already on the filing by the time this runs.
             *  - MODE_APPLY on a permit with NO office form submits immediately
             *    too — there is nothing further for the applicant to give, so
             *    holding it back would strand it.
             *  - MODE_APPLY on a form-bearing permit records the choice and
             *    stops. `submitClearanceForm()` below finishes the job when the
             *    applicant saves the sheet.
             *
             * `submitted_at` moves with the submission rather than with the
             * choice, for the same reason: it is the date the office received
             * something.
             */
            if ($mode === ApplicationPermitType::MODE_APPLY && $type->hasOfficeForm()) {
                return $row->fresh();
            }

            $row->update(['submitted_at' => now()]);
            $this->transitionClearance(
                $row,
                ClearanceStatus::ForApproval,
                $mode === ApplicationPermitType::MODE_UPLOAD
                    ? 'Applicant handed in a permit they already hold.'
                    : 'Applicant applied for this permit.',
            );

            /*
             * ── A renewal's uploaded copy routed NOBODY, and is now refused ──
             *
             * Client's decision, 17 September 2026, asked directly: on a
             * business permit renewal the applicant uploads certificates those
             * offices have ALREADY issued, and the offices are not involved —
             * no assignment, no office form, no inspection. BPLO reads the
             * copies at Final Approval.
             *
             * Without this the decision was only half built: `outstandingClearances`
             * counted such a permit as satisfied, so BPLO could approve — but
             * the upload had still opened a queue item at CHO, BFP, OBO, CENRO
             * and CPDO, each asking an office to read a certificate it issued
             * itself last year, and each starting a service-time clock against
             * work nobody had handed over.
             *
             * KEPT AS A GUARD, not as a path. The mode is refused for renewals
             * at the top of this method (client, 3 October 2026), so nothing
             * should reach here in that combination. It stays because being
             * wrong about that is silent and expensive: one renewal upload
             * slipping through opens a queue item at CHO, BFP, OBO, CENRO and
             * CPDO, each asking an office to read a certificate it issued
             * itself last year, and each starting a service-time clock against
             * work nobody handed over. Two lines are cheaper insurance.
             *
             * An upload on a NEW filing is refused at the top too. An
             * AMENDMENT's still routes and is still inspected —
             * `ClearanceService::submitHeld` carries the client's decision of
             * 6 September, *"the LGU inspects the premises, not the
             * paperwork"* — and that stands where it was made.
             */
            $renewalUpload = $mode === ApplicationPermitType::MODE_UPLOAD
                && $app->application_type === ApplicationType::Renewal;

            if ($type->issuing_department_id !== null && ! $renewalUpload) {
                $this->routeTo($app, $type->issuing_department_id);
            }

            $this->refreshReadiness($app);

            return $row->fresh();
        });
    }

    /**
     * A CEC has just been issued — open the DENR permits it leaves outstanding.
     *
     * ── What this is ──────────────────────────────────────────────────────────
     *
     * MCG-CENRO-FO-001's footnote: "The following required DENR permit/s must be
     * submitted/complied to this office within six (6) months upon issuance of
     * the CEC, on or before ______, otherwise the CEC issued will be
     * automatically revoked."
     *
     * The applicant has been SHOWN that list since the form was rebuilt; until
     * now there was nowhere to hand the documents in. `officer_requests` — Other
     * Requirements — is exactly that bin: one office asking one applicant for
     * one document, with a due date, an upload, a status and a review. It has
     * all of it already; nothing pointed at it.
     *
     * Raised by the system rather than by an officer (client's choice of five
     * options, 9 September 2026), so the six-month clock starts on the day the
     * certificate is minted rather than on the day somebody remembers, and
     * `requested_by_user_id` is null — see the migration that allowed it.
     *
     * ── Three properties worth keeping ───────────────────────────────────────
     *
     * ONLY FOR CEC. Every other clearance issues a certificate that is the end
     * of its own story; this is the one whose issuance creates new obligations.
     *
     * IDEMPOTENT. `grantClearance` is reachable more than once — a re-inspection
     * conducted after a grant runs it again — so this keys on the title it
     * writes. A second pass finds the rows and adds nothing.
     *
     * SILENT WHEN THE TABLE CANNOT PLACE THE BUSINESS. `outstandingFor` returns
     * nothing for a trade CENRO's table does not list, and raising no
     * requirement is the right answer there: the office decides what that
     * business owes, and inventing due-dated obligations from a row we could not
     * match would be worse than leaving them to it.
     */
    private function raiseDenrRequirements(Application $app, PermitType $type): void
    {
        if ($type->code !== 'CEC' || $type->issuing_department_id === null) {
            return;
        }

        $app->loadMissing('business.lines.psicCode');

        $categories = array_values(array_filter(array_map(
            fn (array $line) => (string) ($line['category'] ?? ''),
            (array) ($app->fee_profile['lines'] ?? []),
        )));
        $psicCodes = $app->business?->lines
            ->map(fn ($line) => (string) ($line->psicCode->code ?? ''))
            ->filter()
            ->values()
            ->all() ?? [];

        $outstanding = DenrRequirements::outstandingFor($categories, $psicCodes);
        if ($outstanding === []) {
            return;
        }

        /*
         * Six months from ISSUANCE, which is now — this runs inside the same
         * transaction that mints the certificate. The paper leaves a blank for
         * the date because a counter clerk has to work it out; here it is the
         * one thing the system can supply that the paper cannot.
         */
        $due = now()->addMonths(6);

        foreach ($outstanding as $code => $meaning) {
            OfficerRequest::firstOrCreate(
                [
                    'application_id' => $app->id,
                    'title' => "DENR {$code} — {$meaning}",
                ],
                [
                    'requested_by_user_id' => null,
                    'department_id' => $type->issuing_department_id,
                    'request_type' => 'document',
                    'status' => OfficerRequestStatus::Pending,
                    'due_date' => $due,
                    'description' => "Your City Environmental Certificate has been issued. This {$code} "
                        .'is issued by the DENR, not by the City. Upload it here once you have it. '
                        .'CENRO requires it within six months of your CEC being issued; a CEC whose '
                        .'DENR permits are not complied with by then can be revoked.',
                ],
            );
        }

        $this->notify->applicationStatus(
            $app,
            $app->status,
            'Your City Environmental Certificate has been issued. '
            .count($outstanding).' DENR document(s) are now due under Other Requirements by '
            .$due->toFormattedDateString().'.',
        );
    }

    /**
     * The applicant saved an office form — hand the permit to its office.
     *
     * The other half of `startClearance()`, and the moment a form-bearing
     * clearance actually becomes the office's work. See the long note there for
     * why it is not the press of Apply.
     *
     * Idempotent by design, because saving a form is something an applicant
     * does repeatedly: it acts ONLY on a permit still sitting at NotStarted or
     * Returned, which are the two states that mean "this is yours to finish".
     * A permit already ForApproval is being re-saved before the office has
     * opened it — nothing to do. One past that has been accepted, and
     * `OfficeFormController::ownerMayEdit` will not have let the save through
     * in the first place.
     *
     * `Returned` is the case worth naming: an office sent the sheet back, the
     * applicant fixed it, and saving is what returns it to the reading queue.
     * `ClearanceStatus::Returned->allowedNext()` lists ForApproval for exactly
     * this, and the office is re-routed because `returnClearance` may have
     * closed the assignment behind it.
     */
    public function submitClearanceForm(Application $app, PermitType $type): void
    {
        $row = $this->pivotFor($app, $type->code);
        if ($row === null) {
            return;
        }

        /*
         * ── Three states may hand a sheet in, not two ────────────────────
         *
         * NotStarted is the first submission and Returned is a correction.
         * REJECTED joined them on 24 September 2026 and is the third: an
         * office has refused the permit, the applicant has applied again —
         * `ClearanceService::isAppliedFor` lets them, and
         * `OfficeFormController::ownerMayEdit` reopens the sheet — and this
         * is the act that puts it back in front of the office.
         *
         * Leaving it out is what the reinstatement test caught. Everything
         * up to here worked: the applicant could apply, could type, could
         * press Submit. This method then returned silently, the permit stayed
         * at Rejected, and the office's Approve failed with "A Rejected
         * permit cannot become For Inspection" — an error about the office's
         * press, on a filing the applicant had already fixed, naming a
         * transition neither of them had asked for.
         *
         * It counts as a resubmission for the note, because that is what it
         * is from the office's side: the same permit, read a second time.
         */
        $resubmitting = in_array(
            $row->status,
            [ClearanceStatus::Returned, ClearanceStatus::Rejected],
            true,
        );
        if (! in_array($row->status, [
            ClearanceStatus::NotStarted,
            ClearanceStatus::Returned,
            ClearanceStatus::Rejected,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($app, $type, $row, $resubmitting) {
            /*
             * The return note and its pointer are cleared HERE, and only here:
             * the applicant has answered what the office asked for, so the
             * instruction has been discharged and leaving it up would highlight
             * a row they have just fixed.
             *
             * `returned_at` survives. It stops being "how long have they been
             * sitting on this" and becomes the record that this permit was sent
             * back once, which is what an officer re-reading it wants to know.
             * See the migration that added it.
             *
             * This cleared `rejection_reason`, a column that is gone. The
             * refusal's own wording lives in `remarks` with every other
             * officer note, so it is cleared by the same line — an applicant
             * who has answered a refusal should not have it sitting on the
             * row the office is about to re-read.
             */
            /*
             * `rejected_at`, `rejection_note` and `rejection_remedy` are NOT
             * in this list, and that is the whole point of their existing.
             * The applicant has answered the instruction, so `remarks` goes;
             * the fact that this permit was refused once does not, because
             * the officer about to re-read it is the person who most needs
             * to know. A clean row is how an office approves what it turned
             * down last week.
             */
            /*
             * ── Nothing goes back with a ticked row still empty ───────
             *
             * The office asked for a document that was never attached and
             * the applicant could press Submit with it still missing, so the
             * office received the identical sheet and spent a second review
             * discovering that.
             *
             * Only EMPTY rows block. A row that already holds a file is one
             * the office called wrong, and requiring a DIFFERENT file would
             * trap an applicant whose document was right all along:
             * `ClearanceStatus` allows Returned -> ForApproval only, so no
             * office can wave one through. That case is answered by the
             * comparison below, which shows the office it did not change.
             */
            $this->refuseEmptyReturnedRows($row);
            $this->refuseUnchangedAfterRefusal($row);

            /*
             * ── And nothing goes in missing what the office asks for ──
             *
             * The check above is about a RETURN — the rows this office
             * named. This one is about the checklist itself, and it is the
             * rule the client asked for on 30 September 2026 reading the
             * Locational Clearance screen: the documentary requirements are
             * required.
             *
             * It lives here and not only in the browser. The browser had
             * the declaration gate since 17 September and the server had
             * nothing, so the rule was a disabled button: anything that
             * posted the submit itself — an old tab, a retried request, a
             * second window left open from before the row was emptied —
             * went straight through. A rule the server does not hold is a
             * suggestion.
             */
            $this->refuseIncompleteChecklist($row);

            /*
             * Recorded BEFORE the pointer and the snapshot are cleared — the
             * only moment both halves of the comparison exist.
             */
            $this->recordClearanceCorrections($row);

            $row->update([
                'submitted_at' => now(),
                'remarks' => null,
                'remarks_target' => null,
                /* The round is over; the history is in the corrections. */
                'returned_state' => null,
            ]);
            $this->transitionClearance(
                $row,
                ClearanceStatus::ForApproval,
                $resubmitting
                    ? 'Applicant resubmitted the office form.'
                    : 'Applicant completed the office form.',
            );

            if ($type->issuing_department_id !== null) {
                $this->routeTo($app, $type->issuing_department_id);
            }

            $this->refreshReadiness($app);
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
        // Suspended or blacklisted: nothing moves toward a permit (refuseWhileOnHold).
        $this->refuseWhileOnHold($row->application?->business);

        /*
         * ── An office may not approve past a requirement it raised ───────
         *
         * Client's decision, 24 September 2026, arrived at while working out
         * whether Return is overused: *"instead of Returning due to a blurry
         * scan, the admin will leave them not approved and will ask for Other
         * Requirements"*. That is a better tool for the document case — the
         * permit stays in the office's own queue instead of being handed back
         * — and it did not work, because nothing held the permit.
         *
         * Open requirements gated `refreshReadiness`, which is the FILING's
         * final readiness, and nothing else. So the office could raise a
         * request for a document and then approve the permit without it,
         * which makes the request advisory — and an advisory request is one
         * nobody relies on, so the officer reaches for Return again.
         *
         * Scoped to THIS office's own requests. An office is answerable for
         * what it asked for; being blocked by a document CENRO wants would be
         * a boundary violation in the other direction, and
         * `refreshReadiness` already holds the whole filing for those.
         *
         * `requested_by_user_id` not null, matching refreshReadiness: a
         * compliance clock this service started after issuance is not an
         * obligation anybody is waiting on.
         */
        $departmentId = $row->permitType?->issuing_department_id;
        if ($departmentId !== null) {
            $openHere = $row->application->officerRequests()
                ->whereNotNull('requested_by_user_id')
                ->where('department_id', $departmentId)
                ->where('status', '!=', OfficerRequestStatus::Fulfilled->value)
                ->count();

            if ($openHere > 0) {
                throw ValidationException::withMessages([
                    'requirements' => [
                        'Your office has asked this applicant for something and has not '
                        .'closed the request. Accept or withdraw it under Other Requirements '
                        .'before approving this permit.',
                    ],
                ]);
            }
        }

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
    /**
     * An office sends one permit back for the applicant to fix.
     *
     * `$target` is the optional POINTER: which checklist row or which answer on
     * the sheet the prose is about, as a stable code the system already owns (a
     * `document_types.code`, or an office-form answer key). Null is a perfectly
     * good return — see the migration that added `remarks_target` for why the
     * text is never parsed to derive this.
     */
    /**
     * @param  array<string, string>  $notes  One remark per returned row,
     *                                        keyed by the same code as $target. The officer's UI refuses to send
     *                                        a return until every ticked row has one, and until 30 September
     *                                        2026 this method had nowhere to put them — so an office typed three
     *                                        notes and the applicant got one paragraph with all three run
     *                                        together and the rows themselves blank.
     */
    public function returnClearance(
        ApplicationPermitType $row,
        string $remarks,
        ?string $target = null,
        array $notes = [],
    ): void {
        DB::transaction(function () use ($row, $remarks, $target, $notes) {
            /*
             * The pointer is REPLACED on every return, including with null. A
             * stale target from a previous round would highlight a row this
             * return is not about, which is worse than highlighting nothing:
             * the applicant would fix the wrong thing and be returned twice.
             */
            $row->update([
                'remarks' => $remarks,
                'remarks_target' => $target,
                /*
                 * Stamped on every return, so "asked for changes 3 days ago" is
                 * about the LATEST request and not the first one. A second
                 * return overwrites it deliberately — the question both screens
                 * ask is how long the applicant has been sitting on what the
                 * office most recently said.
                 */
                'returned_at' => now(),
            ]);
            /*
             * Replaced as a SET, scoped to THIS permit — the same reasoning
             * as the pointer above: a note from a previous round sits under
             * a row this round is not about. `permit_type_id` keeps it clear
             * of BPLO's main-form notes, which live in the same table with a
             * null in that column and are cleared by their own return.
             */
            ApplicationReturnNote::where('application_id', $row->application_id)
                ->where('permit_type_id', $row->permit_type_id)
                ->delete();
            $this->writeReturnNotes($row->application_id, $row->permit_type_id, $notes);

            $this->transitionClearance($row, ClearanceStatus::Returned, $remarks);
            $this->notify->applicationStatus(
                $row->application,
                $row->application->status,
                $row->permitType->name.' was returned for revision: '.$remarks,
            );
        });
    }

    /**
     * ── `rejectClearance()` was here, was removed, and is back ───────────────
     *
     * It was deleted on 17 September 2026 — *"I think Return is enough
     * already."* — because nothing could reach it and, under the flow of that
     * week, nothing needed to: the business permit was withheld until every
     * clearance was approved, so an unapprovable permit was punished by the
     * filing simply never finishing.
     *
     * The LGU moved the release on 24 September 2026. The certificate is out as
     * soon as the applicant pays, so withholding is no longer available as a
     * sanction and *"can be suspended if the other permits applied to were
     * rejected"* is the rule instead. That needs a refusal an office can
     * actually record, which is what this is.
     *
     * `refileClearance()` has NOT come back and is not needed. The way out of a
     * rejection is the ordinary apply path — `Rejected → ForApproval` is legal
     * in ClearanceStatus::allowedNext, and `startClearance` is the door — so
     * there is no second mechanism to keep in step with the first. That was
     * half of what made the August pair dead code.
     */

    /**
     * An office refuses ONE permit as applied for. Not a correction — a refusal.
     *
     * ── Return versus Reject, which the officer has to get right ─────────────
     *
     * `returnClearance` above is for everything fixable, and it repeats as often
     * as it needs to: a cut-off scan, an expired lease, a wrong answer. Nothing
     * happens to the business permit, because nothing has been decided.
     *
     * This is the other kind. The office is saying the permit cannot be granted
     * on this application — the premises fail, the use is not allowed here, the
     * inspection found something no re-upload fixes. It costs the applicant
     * their business permit until it is settled, which is why the reason is
     * mandatory and why it is quoted to them verbatim rather than summarised.
     *
     * ── Why the whole filing is not rejected instead ─────────────────────────
     *
     * `rejectApplication` exists and is a different act on a different object:
     * BPLO deciding the business may not be licensed at all. One office
     * refusing one clearance is not that, and the LGU was explicit that the
     * business permit is SUSPENDED rather than destroyed — a suspension can be
     * lifted, a rejected filing cannot, and the applicant would have to pay and
     * file again for a problem they may be able to fix this afternoon.
     *
     * Refuses an already-issued permit. Once a certificate is minted and
     * numbered, un-issuing it is a revocation of a legal instrument, which is
     * not this control — see `PermitStatus::Revoked`, which has no writer yet
     * and should not gain one by accident.
     */
    public function rejectClearance(
        ApplicationPermitType $row,
        string $reason,
        string $remedy = '',
        /*
         * Which rows this refusal is about, the same comma-joined pointer
         * a return carries. Optional: a refusal can be about the business
         * rather than about any one answer — "the premises are not zoned
         * for this" names no field — and forcing a tick would make the
         * officer invent one.
         */
        ?string $target = null,
        /** @var array<string, string> What is wrong with each named row. */
        array $notes = [],
    ): void {
        $reason = trim($reason);
        $remedy = trim($remedy);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Say why this permit is being refused. The applicant is shown this.'],
            ]);
        }

        $app = $row->application;

        if ($row->status === ClearanceStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => [
                    'This permit has already been issued. Refusing it now would be a '
                    .'revocation, which is not done from here.',
                ],
            ]);
        }

        /*
         * ── Only after a visit ──────────────────────────────────────────
         *
         * The legality table in ClearanceStatus already forbids the
         * transition, so this is the second guard rather than the only one —
         * and it is worth having, because the message it throws is the one an
         * officer reads. "A For Approval permit cannot become Rejected" is
         * true and tells them nothing about what to do instead.
         *
         * Checked BEFORE any write, like `approveAssignment`, so a refused
         * refusal leaves nothing behind.
         */
        if ($row->status !== ClearanceStatus::ForInspection) {
            throw ValidationException::withMessages([
                'status' => [
                    'A permit can only be refused after its inspection, because refusing it '
                    .'suspends the business permit. If a document or an answer is wrong, '
                    .'return it for correction or ask for the document instead; if the '
                    .'business should not be licensed at all, that is BPLO’s decision.',
                ],
            ]);
        }

        DB::transaction(function () use ($row, $app, $reason, $remedy, $target, $notes) {
            /*
             * `remarks` AND the refusal columns, which look redundant and are
             * not. `remarks` is what the applicant's card reads as the current
             * instruction and is cleared when they answer it; the three below
             * survive that, because the office re-reading this permit needs to
             * know it has refused it before — see the migration of
             * 24 September 2026 for the failure that motivated them.
             */
            $row->update([
                'decided_at' => now(),
                'remarks' => $reason,
                /*
                 * Which rows, so the applicant's correction dialog can draw
                 * them — the same field a return writes, read by the same
                 * screens. A refusal naming nothing stores null and the
                 * applicant gets the two sentences, as before.
                 */
                'remarks_target' => $target,
                /*
                 * What the sheet said at the moment of refusal, so an
                 * untouched resubmission can be refused rather than
                 * costing the office a second review. `returned_state`
                 * already means "what it said when this office last handed
                 * it back", and a refusal is a handing back.
                 */
                'returned_state' => ClearanceSnapshot::capture(
                    $app,
                    $row->permitType->code,
                    ReturnTargets::parse($target),
                ),
                'rejected_at' => now(),
                'rejection_note' => $reason,
                'rejection_remedy' => $remedy !== '' ? $remedy : null,
            ]);

            /* One note per named row, replacing any from an earlier round. */
            ApplicationReturnNote::where('application_id', $row->application_id)
                ->where('permit_type_id', $row->permit_type_id)
                ->delete();
            $this->writeReturnNotes($row->application_id, $row->permit_type_id, $notes);
            /*
             * And a row of its own. `rejected_at` above is the LATEST refusal
             * — what the office's "refused before" banner reads — and a
             * second refusal after the applicant re-applies overwrites it.
             * The Reports tab counts refusals per period, so each one is kept
             * here as well (migration of 1 October 2026).
             */
            DB::table('clearance_refusals')->insert([
                'application_permit_type_id' => $row->id,
                'application_id' => $row->application_id,
                'permit_type_id' => $row->permit_type_id,
                'refused_at' => $row->rejected_at,
                'reason' => $reason,
                'remedy' => $remedy !== '' ? $remedy : null,
                'refused_by_user_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->transitionClearance($row, ClearanceStatus::Rejected, $reason);

            /*
             * The office's own assignment is closed by the refusal. It has
             * finished deciding; leaving the item open would keep the filing in
             * its queue for a permit it has already ruled on.
             */
            $this->completeAssignment($app, $row->permitType->issuing_department_id, $reason);
        });

        $this->notify->clearanceRejected($app, $row->permitType, $reason);

        $this->suspendOutcomePermit($app, $row->permitType, $reason);

        /*
         * The filing's own readiness is rechecked, and this is the recheck the
         * old tombstone said had to come back with any route out of Approved.
         * A refusal can land after the filing has reached `for_final_approval`
         * on a renewal, and without this BPLO would hold an Approve over an
         * application that no longer qualifies.
         */
        $this->refreshReadiness($app->fresh());
    }

    /**
     * Suspend the business permit this filing released, naming the reason.
     *
     * ── Automatic, on the client's choice of 24 September 2026 ───────────────
     *
     * Asked whether a rejection should suspend by itself or raise a decision for
     * BPLO, the client chose automatic with a manual lift. The reason is the gap:
     * a business whose fire clearance has been refused should not keep trading
     * because nobody opened a queue that morning. BPLO can still lift it — see
     * `liftOutcomeSuspension` — and every lift is audited with a reason, so the
     * discretion is preserved without the permit staying live by default.
     *
     * Only an ACTIVE permit is suspended. An expired or superseded certificate
     * is not a thing the business can trade on, and moving it to Suspended would
     * lose the fact of how its term actually ended — the same argument
     * `PermitStatus::Superseded` was added for.
     *
     * Idempotent, because two offices can refuse two permits on one filing. The
     * second refusal finds the permit already suspended, changes nothing, and
     * still notifies — the applicant needs to know about the second reason even
     * though the state did not move.
     */
    private function suspendOutcomePermit(Application $app, PermitType $refused, string $reason): void
    {
        $permit = $this->outcomePermitFor($app);
        if ($permit === null) {
            return;
        }

        if ($permit->status === PermitStatus::Active) {
            // Suspension retires the certificate; keep it as it stood (Audit Log 1).
            $snapshot = Audit::snapshot($permit);
            $permit->update(['status' => PermitStatus::Suspended]);

            Audit::log('permit.suspended', $permit, [
                'application_id' => $app->id,
                'business_id' => $app->business_id,
                'because_permit_type_id' => $refused->id,
                'because_permit_type' => $refused->name,
                'reason' => $reason,
            ], $snapshot);
        }

        $this->notify->outcomePermitSuspended($app, $permit, $refused, $reason);
    }

    /**
     * Should the suspension still stand? Called whenever a permit is granted.
     *
     * ── The reinstatement rule, run as the mirror of the suspension ──────────
     *
     * The client chose automatic reinstatement on 24 September 2026: the
     * applicant re-applies for the refused permit, the office approves it, and
     * the business permit returns to Active by itself.
     *
     * Written as "is anything still refused" rather than "was this the one that
     * caused it", deliberately. Two offices can refuse on one filing, and a
     * rule that reinstated on the first approval would hand the permit back
     * while a second refusal stood. Asking the whole filing means the two rules
     * cannot disagree — there is one condition, and suspension is its true
     * branch and reinstatement its false one.
     *
     * A permit that is not Suspended is left alone. In particular this never
     * revives a Revoked one: a revocation is a different decision by a different
     * authority, and an office approving a sanitary permit is not a review of it.
     */
    public function reconsiderSuspension(Application $app): void
    {
        /*
         * A business the super admin has suspended or blacklisted keeps every
         * permit suspended, whatever its clearances say. An office passing a
         * visit used to bring the Business Permit back here while the
         * business stayed barred (scenario run, owner-clearances 41).
         */
        if ($this->onHold($app)) {
            return;
        }

        /*
         * Nor on a filing BPLO rejected. `rejectApplication` suspends its
         * certificate because the FILING was refused, and no clearance row
         * records that — so the test below read "nothing here is refused"
         * and reinstating the business revived the permit of a rejected
         * filing (scenario run, permit-suspend-revoke 21).
         */
        if ($app->status === ApplicationStatus::Rejected) {
            return;
        }

        $app->load('permitTypes');
        $stillRefused = $app->permitTypes->contains(
            fn (PermitType $pt) => $pt->pivot->status === ClearanceStatus::Rejected,
        );

        if ($stillRefused) {
            return;
        }

        /*
         * ── EVERY suspended permit on the filing, not only the Mayor's ────
         *
         * This read `outcomePermitFor` alone, which was right while a refused
         * clearance was the only thing that could suspend anything: that
         * cause only ever touches the business permit.
         *
         * `suspendPermitsForBusiness` suspends all of them, so the way back
         * has to be able to return all of them — otherwise a reinstated
         * business would keep a suspended Sanitary Permit for ever, with
         * nothing in the system able to clear it. The condition above is
         * unchanged and still decides: no refusal on this filing means
         * nothing here is being held for one.
         */
        $suspended = $app->permits()
            ->where('status', PermitStatus::Suspended->value)
            ->get();

        foreach ($suspended as $permit) {
            $permit->update(['status' => PermitStatus::Active]);

            Audit::log('permit.reinstated', $permit, [
                'application_id' => $app->id,
                'business_id' => $app->business_id,
                'reason' => 'Nothing on this application is refused, and its business is active.',
            ]);

            $this->notify->outcomePermitReinstated($app, $permit);
        }
    }

    /**
     * Refuse while the business is suspended or blacklisted.
     *
     * The one refusal behind every act that moves a filing toward a permit:
     * BPLO's approvals and its counter payment, an office's approval and a
     * passing inspection, the Debug panel's versions of those, BPLO lifting a
     * permit's suspension, and the minting itself in `issuePermitFor`.
     * Return, Reject, messages and reading stay open — none of them hands the
     * business anything. See `Business::filingsOnHoldReason`
     * for what is on hold and why.
     *
     * Thrown before anything is written, so the officer reads the sentence
     * instead of finding half of an action done.
     */
    public function refuseWhileOnHold(?Business $business): void
    {
        $reason = $business?->filingsOnHoldReason();

        if ($reason !== null) {
            throw ValidationException::withMessages(['status' => [$reason]]);
        }
    }

    /** Is this filing's business suspended or blacklisted? */
    private function onHold(Application $app): bool
    {
        return $app->business?->filingsOnHoldReason() !== null;
    }

    /**
     * A business was suspended or blacklisted. Its live permits follow.
     *
     * ── Why this cascades at all ─────────────────────────────────────────────
     *
     * Client's decision, 24 September 2026. Until then the two sanctions did
     * not speak: `businesses.status` barred the owner from filing, and the
     * certificates carried on reading Active — so a business suspended for
     * violations printed a clean permit and answered VALID to the QR check at
     * the counter, which is the one place the sanction most needed to land.
     *
     * Only ACTIVE permits move. An expired or superseded certificate is not
     * something the business can trade on, and rewriting its status would lose
     * how its term actually ended — the argument `PermitStatus::Superseded`
     * was added for. A permit already Suspended is left alone: it is already
     * where this would put it, and its own reason still stands.
     *
     * Every permit the business holds, not just the Mayor's Permit. A shop
     * whose licence is suspended for violations should not be able to show a
     * valid Sanitary Permit against the same premises.
     */
    public function suspendPermitsForBusiness(Business $business, string $reason): int
    {
        $permits = $business->permits()->where('status', PermitStatus::Active->value)->get();

        foreach ($permits as $permit) {
            // Suspension retires the certificate; keep it as it stood (Audit Log 1).
            $snapshot = Audit::snapshot($permit);
            $permit->update(['status' => PermitStatus::Suspended]);

            Audit::log('permit.suspended', $permit, [
                'business_id' => $business->id,
                'cause' => 'business_status',
                'reason' => $reason,
            ], $snapshot);
        }

        return $permits->count();
    }

    /**
     * A business was reinstated. Bring back the permits its suspension took.
     *
     * ── How it knows which those were, without storing it ────────────────────
     *
     * It does not need a column, and the reasoning is worth stating because a
     * `suspended_cause` column was the obvious first answer and would have
     * meant a migration against the live register.
     *
     * A permit is suspended for one of three reasons: this business was
     * sanctioned, a clearance on its filing was refused, or BPLO rejected the
     * filing itself. The last two are DERIVABLE — the refusal is still sitting
     * on the pivot row, the rejection on the filing's status — so "no
     * clearance on this filing is rejected, and the filing is not" is
     * precisely "the cause must have been the business status", and
     * `reconsiderSuspension` already asks that question for the other half of
     * this feature. (It asked only about the clearances until the scenario
     * run found a rejected filing's permit revived this way.)
     *
     * So the two rules cannot disagree, and the overlap falls out correctly
     * without a special case: a permit suspended for violations ON a filing
     * that also has a refused clearance stays suspended when the business is
     * reinstated, because the clearance reason has not gone anywhere.
     *
     * A permit whose filing has been deleted is skipped rather than revived.
     * Nothing can answer the question for it, and guessing "probably fine" on
     * a certificate somebody sanctioned is the wrong way to be wrong.
     */
    public function restorePermitsForBusiness(Business $business): int
    {
        $permits = $business->permits()
            ->where('status', PermitStatus::Suspended->value)
            ->with('application')
            ->get();

        $restored = 0;
        foreach ($permits as $permit) {
            if ($permit->application === null) {
                continue;
            }

            $this->reconsiderSuspension($permit->application);

            if ($permit->fresh()->status === PermitStatus::Active) {
                $restored++;
            }
        }

        return $restored;
    }

    /**
     * A business came off suspension or blacklisting. Move on what was held.
     *
     * Two kinds of filing wait while a business is on hold, and both pick up
     * here, where they stopped:
     *
     *  - one whose payment landed during the hold: a KwikPay order opened
     *    before the suspension and paid after it. `onPaymentCompleted` kept
     *    the money and left the filing at Pending Payment; it runs again now,
     *    exactly as if the money had just arrived, so the owner never pays
     *    twice (Ken's decision after the October 2026 scenario run). Paid is
     *    the ledger's answer — a completed payment and nothing left due —
     *    because that is what the owner would otherwise be asked for again.
     *  - one whose last clearance or requirement came in during the hold.
     *    `refreshReadiness` left it where it was, and is asked again.
     *
     * Called wherever a business leaves Suspended or Blacklisted for Active or
     * Flagged. A business still on hold — its owner's blacklisting outlives
     * its own status — is left alone.
     *
     * Each filing in its own transaction. One that fails is reported and left
     * where it was, rather than taking the status change the super admin has
     * just made, and every other filing, down with it.
     */
    public function releaseHeldFilings(Business $business): void
    {
        if ($business->filingsOnHoldReason() !== null) {
            return;
        }

        $waiting = $business->applications()
            ->whereIn('status', [
                ApplicationStatus::PendingPayment->value,
                ApplicationStatus::AwaitingOtherPermits->value,
            ])
            ->get();

        foreach ($waiting as $app) {
            try {
                DB::transaction(function () use ($app) {
                    if ($app->status !== ApplicationStatus::PendingPayment) {
                        $this->refreshReadiness($app);

                        return;
                    }

                    $paid = $app->payments()
                        ->where('status', PaymentStatus::Completed->value)
                        ->latest('id')
                        ->first();

                    if ($paid !== null && PermitFees::balance($app)['balance_due'] <= 0) {
                        $this->onPaymentCompleted($paid);
                    }
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * BPLO lifts a suspension on its own judgement, with a recorded reason.
     *
     * The discretion half of *"can be suspended"*. The suspension fires by
     * itself so nothing slips, and this is how a human overrules it — an office
     * that refused in error, or a refusal BPLO judges not to bear on the
     * business permit.
     *
     * The refusal is NOT cleared. The permit that was refused stays refused,
     * because that is the issuing office's decision and BPLO lifting a
     * suspension is not BPLO granting somebody else's permit. What it means is
     * that the business may trade on its business permit while that clearance
     * is still unsettled, which is exactly the judgement being recorded.
     *
     * A consequence worth stating: because the refusal stands,
     * `reconsiderSuspension` will not touch this permit again — it only ever
     * moves a Suspended one — so a lift is final until somebody suspends again.
     */
    public function liftOutcomeSuspension(Permit $permit, string $reason): Permit
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Say why the suspension is being lifted. This is audited.'],
            ]);
        }

        $app = $permit->application;

        if ($permit->status !== PermitStatus::Suspended) {
            throw ValidationException::withMessages([
                'permit' => [
                    'This business permit is '.$permit->status->label().', not suspended.',
                ],
            ]);
        }

        /*
         * Not while the super admin has the business suspended or blacklisted.
         * That sanction is theirs to lift, on Owner Status; a lift here made
         * the certificate Active and /verify valid while the business stayed
         * barred (scenario run, admin-records-permits-map 22).
         */
        $this->refuseWhileOnHold($permit->business);

        $permit->update(['status' => PermitStatus::Active]);

        Audit::log('permit.suspension_lifted', $permit, [
            'application_id' => $app?->id,
            'business_id' => $permit->business_id,
            'reason' => $reason,
        ]);

        /*
         * A permit whose filing has been deleted is still a permit, and the
         * lift is still worth recording — the audit row above is keyed on the
         * certificate. Only the applicant's notification needs the filing, so
         * only that is skipped.
         */
        if ($app !== null) {
            $this->notify->outcomePermitReinstated($app, $permit, $reason);
        }

        return $permit;
    }

    /**
     * Take a permit away (checklist item 23, question A26).
     *
     * ── Who, and what may be revoked ─────────────────────────────────────────
     *
     * BPLO and the super admin, through `permit.revoke` on the route; Ken's
     * decision for the checklist. Any certificate type, because both roles read
     * the whole register and the screen offers it wherever they can see a row —
     * whether BFP should be the one to revoke its own FSIC is still open in A26.
     *
     * Only a certificate that is in force can be revoked: Active, or Suspended
     * (a suspension is the lighter version of the same act, and escalating it
     * is a real case). Expired and superseded certificates have already stopped
     * being valid, and revoking one would write an enforcement act onto a paper
     * nobody can trade on — a record that says something happened for no
     * effect. A revoked permit is refused too, so a double submit cannot
     * overwrite the first reason and date.
     *
     * ── What it writes ───────────────────────────────────────────────────────
     *
     * The status, `revoked_at` and `revoked_reason` on the permit, in one
     * update, so the register table's two revocation columns and the status
     * chip cannot disagree. Then an audit row naming the permit, the business
     * and the reason — Audit::log records the acting officer. Then the owner's
     * notice, which push() also e-mails.
     *
     * Final. There is no un-revoke: whether a revocation can be reversed at all
     * is A26's third question, and until it is answered the remedy is a fresh
     * application. `reconsiderSuspension` only ever moves a Suspended permit,
     * so it cannot quietly revive this one either.
     */
    public function revokePermit(Permit $permit, string $reason): Permit
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Say why this permit is being revoked. The owner is told, and it is audited.'],
            ]);
        }

        if (! in_array($permit->status, [PermitStatus::Active, PermitStatus::Suspended], true)) {
            throw ValidationException::withMessages([
                'permit' => [
                    "Permit {$permit->permit_number} is {$permit->status->label()}, so there is nothing in force to revoke.",
                ],
            ]);
        }

        DB::transaction(function () use ($permit, $reason) {
            $from = $permit->status;
            // Revoking retires the certificate for good; keep it as it stood
            // (Audit Log 1). Taken before the update, like suspension's.
            $snapshot = Audit::snapshot($permit);

            $permit->update([
                'status' => PermitStatus::Revoked,
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ]);

            Audit::log('permit.revoked', $permit, [
                'permit_number' => $permit->permit_number,
                'business_id' => $permit->business_id,
                'application_id' => $permit->application_id,
                'from' => $from->value,
                'reason' => $reason,
            ], $snapshot);

            $this->notify->permitRevoked($permit, $reason);
        });

        return $permit;
    }

    /**
     * The business permit this filing issued, or null.
     *
     * Scoped to the APPLICATION and not to the business, which matters on a
     * business that has renewed: `permits` on the business would find last
     * year's superseded certificate too, and suspending or reviving that one
     * would rewrite a closed term.
     */
    private function outcomePermitFor(Application $app): ?Permit
    {
        return $app->permits()
            ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
            ->latest('id')
            ->first();
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
        /*
         * Both halves of the question, through the pivot's own predicate: the
         * permit is for inspection AND the filing is not decided. Asking only
         * the first let an office book a visit against a filing BPLO had
         * already refused — see ApplicationPermitType::awaitingInspection().
         */
        if (! $row->awaitingInspection()) {
            throw ValidationException::withMessages([
                'status' => $row->application?->status?->isTerminal()
                    ? ['This application has been decided. Its permits can no longer be acted on.']
                    : ['An inspection can only be scheduled once this permit’s paperwork is approved.'],
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
        /*
         * ── A decided filing takes no more visits ────────────────────────────
         *
         * This was the worst of the three unguarded doors, and the only one that
         * did more than waste a row. Measured 18 September 2026 on a filing BPLO
         * had REJECTED: conduct a passing visit and this method answered 200,
         * moved the permit `for_inspection → approved` and issued the
         * certificate. A Fire Safety clearance minted against a filing the LGU
         * had refused, which the business could then present.
         *
         * Refused BEFORE the row is written, so a dead filing does not collect a
         * visit record either — the officer gets told why instead of watching a
         * conducted visit achieve nothing. The wording is the flow's own, from
         * `ApplicationStatus::allowedNext()`.
         */
        if ($inspection->application?->status?->isTerminal() ?? false) {
            throw ValidationException::withMessages([
                'status' => ['This application has been decided. Its permits can no longer be acted on.'],
            ]);
        }

        /*
         * A pass grants and issues, so it is refused while the business is
         * suspended or blacklisted — before the visit is written, for the
         * reason above. A failed visit moves nothing and is still recorded.
         */
        if ($result->progresses()) {
            $this->refuseWhileOnHold($inspection->application?->business);
        }

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
             * The owner is told. A failed visit used to be recorded in silence
             * — a pass reached them through grantClearance(), a failure through
             * nothing — so the one inspection result they have to act on (fix
             * the findings before the re-inspection) was the one they could only
             * discover by opening the filing. Found while listing every owner
             * update for the e-mail work, 24 September 2026. The findings are
             * the inspector's own words, as a returned clearance's remarks are.
             */
            $app = $inspection->application;
            $office = $inspection->department?->name ?? 'The office';
            $this->notify->applicationStatus(
                $app,
                $app->status,
                "{$office} inspection did not pass."
                .($findings ? " Findings: {$findings}" : '')
                .' The office will schedule a re-inspection.',
            );

            return;
        }

        $row = $this->pivotForDepartment($inspection->application, $inspection->department_id);
        if ($row === null || ! $row->awaitingInspection()) {
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
    /**
     * The required clearances this filing is still waiting on.
     *
     * ── One predicate, because there were two identical copies of it ─────────
     *
     * `refreshReadiness` and `approveOverall` filtered the same way, in the same
     * words, in two places — "required, and the pivot status is outstanding" —
     * and they must never disagree: one decides whether BPLO is *offered* the
     * final approval and the other whether it is *allowed*. A rule split across
     * those two is a filing BPLO can see and cannot act on.
     *
     * ── ONE rule for every filing type: its own office has to have approved ─
     *
     * A renewal used to count an UPLOADED copy as satisfied on the strength of
     * its mode alone (client's decision, 17 September 2026). That is what put
     * such a filing in front of BPLO at Final Approval with copies to read.
     *
     * Both halves went on 3 October 2026, at the client's request: *"an admin
     * verifying an uploaded other permit will be useless if the system already
     * tells them whether they are still valid or not."* `startClearance` now
     * refuses the mode on a renewal, and `refreshReadiness` no longer sends a
     * ready renewal to BPLO.
     *
     * The exception is DELETED rather than left unreachable. Standing, it would
     * be a second and quieter way for a renewal to be called satisfied with no
     * office having approved anything — the very thing the client asked to
     * remove, surviving in the one place nobody would think to look.
     *
     * The RETURNED carve-out went with it and is not missed: it existed only to
     * stop a returned upload still counting as satisfied by its mode, and with
     * no mode exception left, anything short of Approved is outstanding.
     *
     * @return Collection<int, PermitType>
     */
    public function outstandingClearances(Application $app): Collection
    {
        return $app->permitTypes
            ->filter(fn (PermitType $pt) => $pt->isRequiredClearance())
            ->reject(fn (PermitType $pt) => $pt->pivot->status === ClearanceStatus::Approved);
    }

    public function refreshReadiness(Application $app): void
    {
        /*
         * ── Nothing moves forward while the business is on hold ─────────────
         *
         * Every forward move below closes the filing or puts it in front of
         * BPLO, and this runs inside OTHER acts — an office closing a
         * requirement it raised, say — so it waits quietly rather than
         * refusing them; `releaseHeldFilings` asks again when the business is
         * put back. The one backward move, out of Final Approval, still
         * happens: a refusal on a held filing is allowed.
         */
        $held = $this->onHold($app);

        /*
         * ── A clearance-only renewal closes itself ───────────────────────────
         *
         * Client's decision, 17 September 2026: it closes the moment its last
         * permit is issued, with no further press. There is nothing left to
         * decide by then — the office accepted the paperwork and passed the
         * visit, and the certificate is in the applicant's hand. Leaving the
         * FILING at For Approval would have it claim an office was
         * still reading something it had already granted.
         *
         * Checked before the status gate below, not folded into it, because the
         * question is different: that gate asks "is BPLO's final approval in
         * play", and on this filing BPLO is not in play at all.
         *
         * Every permit ON THE FILING, not just the required ones. A solo
         * renewal's whole purpose is the permits it carries, so an optional one
         * left outstanding is still outstanding — where on a new filing an
         * optional permit must never block the business permit.
         */
        if (! $held && $app->status === ApplicationStatus::ForApproval && $app->defersPayment()) {
            $app->load('permitTypes');

            $allGranted = $app->permitTypes->every(
                fn (PermitType $pt) => $pt->pivot->status === ClearanceStatus::Approved,
            );

            if ($allGranted && $app->permitTypes->isNotEmpty()) {
                $app->update(['decided_at' => now()]);
                $this->transition(
                    $app,
                    ApplicationStatus::Approved,
                    'Every permit on this renewal has been issued.',
                );
                $this->notify->permitsIssued($app);
            }

            return;
        }

        if (! in_array($app->status, [
            ApplicationStatus::AwaitingOtherPermits,
            ApplicationStatus::ForFinalApproval,
        ], true)) {
            return;
        }

        $app->load('permitTypes');

        $outstanding = $this->outstandingClearances($app);

        /*
         * ── An open Other Requirement holds the filing back ──────────────────
         *
         * This counted CLEARANCES and nothing else, so a filing whose permits
         * were all approved walked into Final Approval with a document an
         * office had asked for still outstanding, and BPLO could issue the
         * business permit without it. The office asked for that document for a
         * reason; issuing the permit over it is the system overruling the
         * reason quietly.
         *
         * Not FULFILLED is the test, which is wider than "waiting on the
         * applicant" and deliberately so. A requirement the owner has answered
         * but the office has not accepted is still a question nobody has
         * closed, and approving on top of it wastes the request as completely
         * as approving before the answer.
         *
         * The cost, stated: an office that raises a requirement and never rules
         * on it blocks the filing, and a requirement has no `cancelled` state —
         * so an office that asked for the wrong thing closes it by approving
         * it. The alternative is a permit issued over an unanswered question.
         *
         * ── Why an officer has to have asked ─────────────────────────────────
         *
         * `whereNotNull('requested_by_user_id')` is the whole difference
         * between a condition and an obligation, and leaving it out froze the
         * product for six months at a time.
         *
         * When CENRO issues a City Environmental Certificate, THIS SERVICE
         * raises the DENR documents the business must hold — due six months
         * from issuance, by the text of the requirement itself. They follow the
         * permit; they are not a condition of it. Counting them meant a filing
         * whose five clearances were all approved sat at
         * `awaiting_other_permits` with nothing anybody could do about it,
         * which the full-lifecycle test caught on the first run.
         *
         * So the line is WHO ASKED. An officer asking this applicant for
         * something before the office will sign blocks; a compliance clock the
         * system started after issuance does not. `requested_by_user_id` is
         * nullable precisely for the system-raised kind.
         */
        $openRequirements = $app->officerRequests()
            ->whereNotNull('requested_by_user_id')
            ->where('status', '!=', OfficerRequestStatus::Fulfilled->value)
            ->count();

        $ready = $outstanding->isEmpty() && $openRequirements === 0;

        if ($ready && ! $held && $app->status === ApplicationStatus::AwaitingOtherPermits) {
            /*
             * ── No filing type stops for BPLO to re-read the permits ─────────
             *
             * The client's question of 18 September 2026 — *"what is the purpose
             * of the BPLO checking if all other permits are legit, when those
             * permits are APPLIED DIRECTLY in BizTrack itself?"* — was answered
             * for new filings then, and renewals were explicitly out of scope.
             * On 3 October 2026 the client put them in scope: *"an admin
             * verifying an uploaded other permit will be useless if the system
             * already tells them whether they are still valid or not."*
             *
             * The stage's one remaining job on a renewal was reading those
             * uploads, and `startClearance` no longer accepts them. So a
             * renewal now stands on the same ground the new-filing case stood
             * on: every clearance applied for here and approved by its own
             * office here, no evidence to weigh that the register does not
             * already hold, and the RA 11032 clock running while it waited.
             *
             * NOT removed: the processing-category branch below. It is not
             * about uploads — it holds a filing nobody has classified so that
             * an officer sets the RA 11032 tier before a permit is issued
             * against its deadline — and it applies to every filing type. That
             * is the only route to For Final Approval left.
             */

            /*
             * ── The one case where BPLO still has something real to do ───────
             *
             * Tested rather than required, because this runs inside the
             * CLEARANCE OFFICE's Approve press: `requireProcessingCategory()`
             * throws, and a filing missing its tier would fail CENRO's own
             * approval with an error about a category CENRO cannot set.
             *
             * It falls back to For Final Approval, and that is not the stage
             * creeping back in. The stage was removed from this path because it
             * had no work in it; here it has work — a named officer must confirm
             * the RA 11032 category against the Citizen's Charter before a
             * permit is issued against its deadline, and then approve. That is a
             * decision, not a rubber stamp.
             *
             * It should be unreachable: `approveMainForm()` already refuses
             * BPLO's first act without a confirmed tier. One filing in the
             * register is past that guard without one, written before it
             * existed, and it is currently at this very status — so this branch
             * is live today rather than defensive.
             */
            if (! $this->hasProcessingCategory($app)) {
                $this->transition(
                    $app,
                    ApplicationStatus::ForFinalApproval,
                    'Every other permit has been approved. BPLO must confirm this filing’s '
                    .'processing category before the business permit can be issued.',
                );

                return;
            }

            /*
             * The remark says what CLOSING the filing means, not that a
             * permit was minted here. It said "business permit issued", which
             * was true until 24 September 2026 and is now two steps out of
             * date — the certificate went out at payment. This lands on
             * BPLO's assignment where an officer reads it back.
             */
            $this->approveOverall(
                $app,
                'Every other permit approved. The application is closed, so no clearance '
                .'on it can suspend the business permit.',
            );

            return;
        }

        if (! $ready && $app->status === ApplicationStatus::ForFinalApproval) {
            $this->transition(
                $app,
                ApplicationStatus::AwaitingOtherPermits,
                // Which of the two it is, because "not ready" with no reason is
                // a filing that stops moving and says nothing about why.
                $outstanding->isEmpty()
                    ? 'An office is waiting on a requirement, so the application is not ready for final approval.'
                    : 'A permit is outstanding again, so the application is not ready for final approval.',
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
        // Suspended or blacklisted: nothing moves toward a permit (refuseWhileOnHold).
        $this->refuseWhileOnHold($app->business);

        /*
         * The processing-category gate stood here until 27 September 2026.
         *
         * It refused to approve a filing nobody had classified, because
         * `Ra11032::tierFor()` was our guess and RA 11032 leaves the
         * classification to the LGU. Malabon has published theirs — new and
         * renewal business permits are Simple — so the tier is now read from
         * the charter and can never be unknown. There is nothing left to
         * confirm, and a gate on a question with one answer is a step that
         * only ever costs an officer a click.
         */

        $app->load('permitTypes');
        $outstanding = $this->outstandingClearances($app);

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

            $this->syncDeclaredFigures($app);

            $row = $this->pivotFor($app, PermitType::OUTCOME_CODE);
            $issuedBusinessPermit = false;
            if ($row !== null && $row->status !== ClearanceStatus::Approved) {
                $row->update(['decided_at' => now()]);
                $row->forceFill(['status' => ClearanceStatus::Approved])->save();
                $this->issuePermitFor($app, $row->permitType);
                $issuedBusinessPermit = true;
            }

            $app->update(['decided_at' => now()]);
            /*
             * "Business permit issued" is not true of every approval any more.
             * A renewal may carry any subset of the permits — a shop whose
             * Sanitary Permit expires in September renews that alone — and such
             * a filing has no business-permit row to issue. The history note is
             * read by the applicant on their own timeline, so it says what
             * actually happened rather than what usually does.
             */
            /*
             * ── What this moment MEANS to the applicant, since the release
             *    moved to payment ─────────────────────────────────────────
             *
             * On a NEW filing `$issuedBusinessPermit` is false now — the
             * certificate was minted at payment — so this takes the second
             * branch, and "every permit has been issued" was true but thin.
             * It reads as a repeat of the step before it, which is exactly
             * what the client asked about: *"why is there Approved at the end
             * even though there is already Permit Released?"*
             *
             * The answer is a real fact and it is the one thing this stage
             * adds: `Approved` is TERMINAL, and `rejectAssignment` refuses a
             * terminal filing — so from this moment no office can refuse a
             * permit and no suspension can follow. Up to here it could.
             *
             * The first branch still exists for the filings that DO mint the
             * permit here: a renewal or amendment, which go back to BPLO
             * after payment and are issued on its press.
             */
            $this->transition(
                $app,
                ApplicationStatus::Approved,
                $issuedBusinessPermit
                    ? 'All requirements met. Business permit issued.'
                    : 'Every other permit is approved. This application is closed, so none of '
                        .'its permits can suspend your business permit.',
            );
            $this->notify->applicationApproved($app);
            $this->notify->permitsIssued($app);
        });
    }

    /**
     * Copy what this filing DECLARED onto the business record.
     *
     * ── Why the business record has to hold these at all ──────────────────
     *
     * Floor area, headcount and delivery vehicles are asked on the Fee Profile
     * step and stored in `applications.fee_profile` — per filing, because they
     * are what the assessment was computed from and rewriting them later would
     * rewrite an assessed bill.
     *
     * The matching columns on `businesses` existed and were DEAD: nothing in
     * the API, the seeders, the factories or the tests had ever written one.
     * That was defensible while nothing read them — and stopped being so the
     * day the amendment form offered to change them. Client, 21 September
     * 2026, having noticed every row reading "Currently: not recorded" on a
     * business whose application declared a floor area of 100: *"Is it human
     * error or system error?"* System. The form was amending columns nothing
     * fills, so its "current" was always blank and its approval wrote where
     * nothing reads.
     *
     * ── The objection this answers ────────────────────────────────────────
     *
     * The original note against a business-level copy (see `FeeProfileDraft`
     * in the web types) was that a headcount belongs to a MOMENT — it is
     * redeclared every January — and the record "would carry the first year's
     * figure forever". True of a copy written once. This is written at EVERY
     * approval, so it carries the latest declaration and the per-filing
     * history stays intact in `fee_profile` where the assessment can still
     * point at it.
     *
     * ── Absent is not "clear it" ──────────────────────────────────────────
     *
     * Only keys the filing actually answered are written. A renewal that
     * leaves the delivery-vehicle count blank is not declaring zero vans, and
     * blanking the register on its say-so would lose a figure nobody asked to
     * lose.
     */
    private function syncDeclaredFigures(Application $app): void
    {
        $business = $app->business;
        $profile = $app->fee_profile;

        if ($business === null || ! is_array($profile)) {
            return;
        }

        /*
         * Fee-profile key => column. The names differ on both sides of three
         * of these, which is exactly why the mapping is written down once
         * rather than inferred at each call site.
         */
        $map = [
            'floor_area_sqm' => 'business_area_sqm',
            'employees' => 'total_employees',
            'male_employees' => 'male_employees',
            'female_employees' => 'female_employees',
            'employees_in_lgu' => 'employees_within_lgu',
        ];

        $changes = [];
        foreach ($map as $key => $column) {
            $value = $profile[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $changes[$column] = $value;
        }

        /*
         * The paper asks for vehicles as two counts — motorised and other —
         * and the register holds one column, so the register gets the total.
         * Summed rather than dropped: "delivery vehicles" on the amendment
         * form means all of them, and showing only the motorised ones as the
         * current figure would invite an applicant to correct a number that
         * was never wrong.
         */
        $vans = ($profile['delivery_vehicles_motorized'] ?? null);
        $other = ($profile['delivery_vehicles_other'] ?? null);
        if ($vans !== null || $other !== null) {
            $changes['delivery_units'] = (int) ($vans ?? 0) + (int) ($other ?? 0);
        }

        if ($changes === []) {
            return;
        }

        /*
         * `forceFill`, because these six are not mass-assignable on `Business`
         * and should not become so: this is the only writer, and the point of
         * naming it here is that there is exactly one.
         */
        $business->forceFill($changes)->save();
    }

    /**
     * BPLO completes an amendment: the register changes here, and only here.
     *
     * ── Applied, never retyped ─────────────────────────────────────────────
     *
     * The client asked whether an officer should change the business details by
     * hand when an amendment arrives. They should not, and this is the answer
     * instead. A hand-retyped register is two records of one fact kept in step
     * by somebody remembering to — the filing says the floor area should be
     * 140, the business says 120, and nothing on either row says which was
     * meant or who last looked. The applicant already stated the new value on
     * the form; approval is what makes it true.
     *
     * So the officer's act is a DECISION, not data entry: they read the
     * affidavit and the supporting documents, then approve, and the values the
     * applicant asked for are written in one transaction.
     *
     * ── What it records ───────────────────────────────────────────────────
     *
     * `old_value` is captured HERE rather than at request time, because what an
     * amendment overwrote is the value at the moment of writing. A business
     * whose area was corrected between filing and approval would otherwise
     * carry an amendment claiming to have replaced a figure that had already
     * gone.
     *
     * ── What it does NOT do ───────────────────────────────────────────────
     *
     * No permit is minted. The amended details are printed on the Mayor's
     * Permit, so reprinting it is a fair question — but `issuePermitFor` would
     * leave the business holding two live business permits for one term, and
     * which one a reader should believe is a decision for BPLO rather than a
     * side effect of this method. Flagged in the amendment doc, not guessed.
     *
     * A field outside `AmendableFields` cannot reach this: the request endpoint
     * refuses it by name. The guard here is the second door, on the principle
     * this codebase keeps relearning — a rule wired into one of two callers is
     * a rule that holds until somebody adds a third.
     */
    public function approveAmendment(Application $app, ?string $remarks = null): void
    {
        // Suspended or blacklisted: nothing moves toward a permit (refuseWhileOnHold).
        $this->refuseWhileOnHold($app->business);

        if ($app->application_type !== ApplicationType::Amendment) {
            throw ValidationException::withMessages([
                'application_type' => ['This is not an amendment, so there are no changes to apply.'],
            ]);
        }

        /*
         * The processing-category gate stood here until 27 September 2026.
         *
         * It refused to approve a filing nobody had classified, because
         * `Ra11032::tierFor()` was our guess and RA 11032 leaves the
         * classification to the LGU. Malabon has published theirs — new and
         * renewal business permits are Simple — so the tier is now read from
         * the charter and can never be unknown. There is nothing left to
         * confirm, and a gate on a question with one answer is a step that
         * only ever costs an officer a click.
         */

        /*
         * ── A move waits for CPDO ────────────────────────────────────────────
         *
         * Client's decision, 19 September 2026: hold the amendment until the
         * zoning clearance for the new address is issued, so the register never
         * shows a business at premises CPDO has not assessed.
         *
         * `outstandingClearances` is the same predicate a new filing's final
         * approval used, which is what makes this a guard rather than a second
         * opinion: the clearance is on the filing (see
         * `permitTypeIdsAtSubmission`), so the question "is anything still
         * outstanding" already has one answer.
         *
         * Only an amendment that moves, changes the activity or enlarges the
         * area carries a clearance (Art. IX §8), so for every other kind this
         * is empty and costs nothing.
         */
        $app->load('permitTypes');
        $outstanding = $this->outstandingClearances($app);

        if ($outstanding->isNotEmpty()) {
            /*
             * Named by what the amendment does, because BPLO cannot clear it
             * themselves and needs to know what CPDO is assessing. A move
             * keeps its original words ("for the new address") — the
             * register-wide refusal message tests read them.
             */
            $why = self::amendmentNeedsLocationalClearance($app);
            $what = match (true) {
                in_array('moves', $why, true) => 'moves the premises',
                in_array('activity', $why, true) => 'changes the line of business',
                in_array('area', $why, true) => 'enlarges the floor area',
                default => 'needs other permits',
            };
            throw ValidationException::withMessages([
                'permits' => [
                    "This amendment {$what}, so it cannot be approved until "
                    .$outstanding->pluck('name')->join(', ')
                    .(in_array('moves', $why, true)
                        ? ' has been issued for the new address.'
                        : ' has been issued for it (City Ordinance No. 24-2018, Art. IX §8).'),
                ],
            ]);
        }

        DB::transaction(function () use ($app, $remarks) {
            $this->completeAssignment($app, $this->bploDepartmentId(), $remarks);

            /*
             * Before `applyAmendments`, so a requested change always wins over
             * the carried figures. An amendment has no fee profile of its own,
             * so in practice this is a no-op here — but the ORDER is the
             * invariant, not the current emptiness of the profile.
             */
            $this->syncDeclaredFigures($app);

            $changed = $this->applyAmendments($app);
            $this->reissueAmendedPermit($app);
            $this->recordAmendmentFee($app, $changed);

            $app->update(['decided_at' => now()]);
            $this->transition(
                $app,
                ApplicationStatus::Approved,
                'Amendment approved. The business record has been updated.',
            );
            $this->notify->applicationApproved($app);
            $this->tellOfficesAboutAmendment($app, $changed);
            $this->tellBploToMoveTheAccount($app);
        });
    }

    /**
     * A CHANGE OF OWNERSHIP is the one amendment approval cannot finish.
     *
     * Client's decision, 21 September 2026: *"Write the owner's name, BPLO
     * moves the account."* The permit prints `business->owner->fullName()` —
     * the ACCOUNT's name — and the new owner may hold no BizTrack account at
     * all, so `AmendableFields` records `owner_name` and applies nothing.
     *
     * Which leaves a gap that has to be closed by a person, and a gap closed
     * by a person is a gap that needs telling. Without this the approval is
     * silent, the reissued certificate prints the OLD owner, and the only
     * record that anything is outstanding is a row in
     * `application_amendments` nobody is looking at.
     *
     * Sent to BPLO rather than the applicant: it is BPLO's action, on BPLO's
     * screen, and the applicant has already been told their amendment was
     * approved.
     *
     * The link is Owner Status, which is where the transfer lives:
     * `BusinessStatusController::transferOwner` takes the new owner's e-mail
     * and a reason, and the page offers it per business.
     *
     * This used to say the transfer did not exist. True when written, false
     * once it shipped, and left standing long enough to mislead — see the note
     * on AmendableFields for what that cost.
     */
    private function tellBploToMoveTheAccount(Application $app): void
    {
        $owner = $app->requestedChanges()
            ->where('field', 'owner_name')
            ->whereNotNull('new_value')
            ->value('new_value');

        if ($owner === null) {
            return;
        }

        $name = $app->business?->name ?? 'A business';

        $officers = User::where('department_id', $this->bploDepartmentId())
            ->where('is_active', true)
            ->get();

        foreach ($officers as $officer) {
            $this->notify->push(
                $officer,
                'amendment',
                'An approved amendment needs the account moved',
                "{$name} was transferred to {$owner}. The permit prints the account holder’s "
                .'name, so reassign the business on the Reassign screen — until then the '
                .'certificate still names the previous owner.',
                '/staff/admin/owners',
            );
        }
    }

    /**
     * Reprint the business permit with the amended details, over the same term.
     *
     * Client's decision, 19 September 2026: reissue and supersede. The old
     * certificate's face is now wrong — that is the whole point of the
     * amendment — and two live permits with different details and nothing
     * saying which to believe is the state `issuePermitFor` already refuses to
     * leave a renewal in.
     *
     * ── Why not `issuePermitFor` ──────────────────────────────────────────
     *
     * It answers a different date question. That method CONTINUES a term: a
     * renewal issued while its predecessor is still valid starts the day after
     * the old one ends, which is right for a renewal and wrong here by a year.
     * An amendment buys no time at all — it reprints the permit the business
     * already holds — so the reissue inherits both dates exactly. Amending in
     * June must not quietly extend cover to next June.
     *
     * Nothing is issued when there is no live permit to reprint. An amendment
     * filed against an expired or revoked permit changes the register, and
     * minting a fresh certificate off the back of it would hand the business
     * something it is not entitled to.
     */
    private function reissueAmendedPermit(Application $app): ?Permit
    {
        // Never an Active certificate for a suspended business (refuseWhileOnHold).
        $this->refuseWhileOnHold($app->business);

        $type = PermitType::where('code', PermitType::OUTCOME_CODE)->first();
        if ($type === null || $app->business_id === null) {
            return null;
        }

        /*
         * The permit this amendment names, not `priorPermitFor()`.
         *
         * That helper answers a renewal's question — which of the TICKED prior
         * permits matches this type — and returns null for anything else by
         * design. An amendment names exactly one permit, on
         * `applications.prior_permit_id`, and the submit gate refuses an
         * amendment that names none, so it is present by construction.
         *
         * Type and ownership are re-checked rather than trusted. The gate
         * verifies the permit belongs to the business; it does not verify it is
         * the BUSINESS permit, and reprinting a Sanitary Permit as the outcome
         * of a business-permit amendment would put the wrong certificate in the
         * applicant's hand.
         */
        $prior = $app->priorPermit;

        if (
            $prior === null
            || $prior->business_id !== $app->business_id
            || $prior->permit_type_id !== $type->id
            || ! $prior->status?->isLive()
        ) {
            return null;
        }

        $permit = Permit::create([
            'application_id' => $app->id,
            'business_id' => $app->business_id,
            'permit_type_id' => $type->id,
            'prior_permit_id' => $prior->id,
            'permit_number' => Numbering::permitNumber($type->permit_number_prefix),
            'status' => PermitStatus::Active,
            // The SAME term, to the day. See the note above.
            'valid_from' => $prior->valid_from,
            'valid_until' => $prior->valid_until,
            'issued_at' => now(),
            'issued_by_user_id' => Auth::id(),
            'issued_details' => PermitFace::capture(
                $app->business?->fresh()?->load(['address.barangay', 'owner', 'lines.psicCode'])
            ),
        ]);

        $prior->update(['status' => PermitStatus::Superseded]);
        Audit::log('permit.superseded', $prior, ['amended_by_application_id' => $app->id]);

        return $permit;
    }

    /**
     * Stack this amendment's fee against the January renewal.
     *
     * Client, 19 September 2026: *"every other permit renewal and every
     * amendment done before business permit renewal on January will have their
     * fee amounts stacked up until they are ready to be paid on the business
     * permit renewal on January."*
     *
     * The same pile the deferred clearance fees go into, swept by the same
     * `sweepDeferredFees()` when the business permit is next renewed. Nothing
     * new was needed to collect it — only to put it there.
     *
     * ── The amount is NOT known, and is not invented ──────────────────────
     *
     * A10-2016 as seeded carries no amendment fee: 0 of the revenue-code rules
     * mention one, and neither does the extract of the ordinance itself
     * (both searched 19 September 2026). So `config('biztrack.amendment_fee')`
     * is ₱0 until BPLO gives a figure, and the row is written anyway.
     *
     * Writing a ₱0 row rather than no row is the deliberate part. The stacking
     * is then real, visible and already correct — the line appears on the
     * January bill saying what it is for, and the day the LGU names a price it
     * is one number here and nothing else changes. No row would leave the
     * feature looking finished while quietly collecting nothing.
     *
     * The permit type is the BUSINESS permit, because that is the permit an
     * amendment amends; `description` is what stops the line printing as a
     * second business-permit fee beside the real one.
     *
     * @param  list<string>  $changed  the fields actually written
     */
    private function recordAmendmentFee(Application $app, array $changed): void
    {
        if ($app->business_id === null || $changed === []) {
            return;
        }

        $type = PermitType::where('code', PermitType::OUTCOME_CODE)->first();
        if ($type === null) {
            return;
        }

        $what = collect($changed)
            ->map(fn (string $f) => AmendableFields::label($f))
            ->join(', ');

        /*
         * ── Nothing here re-rates a change of trade ─────────────────────────
         *
         * The per-line surcharge was added here on 21 September 2026 to price
         * an ADDITIONAL line of business, and went out with it the same day:
         * one business holds one line, so no amendment can change the count
         * the surcharge is charged per.
         *
         * A line REPLACED is not re-rated either. The flat schedule does not
         * vary by trade, and whether the ordinance's own rates do is the open
         * question about which of the two pricing systems is authoritative —
         * guessing would put a figure on a bill nobody can point at a rule
         * for. So a change of trade carries the amendment fee and no more,
         * and the January renewal reassesses the business from scratch as it
         * does every year.
         */
        UnbilledPermitFee::updateOrCreate(
            // One fee per amendment, so a replayed approval cannot stack it
            // twice — the same reason `applyAmendments` skips applied rows.
            ['application_id' => $app->id, 'permit_type_id' => $type->id],
            [
                'business_id' => $app->business_id,
                /*
                 * A SETTING, not a fee rule: `fee_rules` is the ordinance and
                 * every row there carries the section it came from, which this
                 * fee does not have. Zero until BPLO names a figure — see
                 * config/biztrack.php for what was searched and why the row is
                 * written at zero rather than skipped.
                 *
                 */
                'amount' => (float) config('biztrack.amendment_fee', 0),
                'description' => 'Amendment — '.$what,
                'incurred_at' => now(),
            ],
        );
    }

    /**
     * Tell the offices whose certificates now describe something else.
     *
     * Client's decision, 19 September 2026: notify, and only for changes that
     * alter what an office actually verified. A trade-name change or a
     * corrected employee count tells CHO nothing it acted on, and notifying
     * five accounts for those is how a channel that matters gets tuned out.
     *
     * This is the TIMELY half. The durable half is
     * `PermitFace::changedSince()`, which can answer "this certificate was
     * issued for a different address" for as long as the permit exists —
     * because a notification is read once and then gone, and six months later
     * nothing on the certificate itself would say its address is stale.
     *
     * @param  list<string>  $changed  fields actually written
     */
    private function tellOfficesAboutAmendment(Application $app, array $changed): void
    {
        $relevant = array_values(array_intersect($changed, AmendableFields::OFFICE_VISIBLE));
        if ($relevant === [] || $app->business_id === null) {
            return;
        }

        $what = collect($relevant)->map(fn (string $f) => AmendableFields::label($f))->join(', ');
        $name = $app->business?->name ?? 'A business';

        /*
         * The offices that ISSUED this business a clearance, not all five.
         * An office that has never granted it anything holds no certificate
         * this could have invalidated, so a notice would be the first it had
         * heard of the business at all.
         */
        $departmentIds = Permit::query()
            ->where('business_id', $app->business_id)
            ->whereIn('status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->with('permitType')
            ->get()
            ->map(fn (Permit $p) => $p->permitType?->issuing_department_id)
            ->filter()
            ->unique()
            ->reject(fn (int $id) => $id === $this->bploDepartmentId())
            ->values();

        if ($departmentIds->isEmpty()) {
            return;
        }

        foreach (User::whereIn('department_id', $departmentIds)->where('is_active', true)->get() as $officer) {
            $this->notify->push(
                $officer,
                'amendment',
                'A business you have certified has changed',
                "{$name} amended its {$what}. A certificate your office issued may describe the previous details — open the business to see what changed.",
                '/staff/admin/records',
            );
        }
    }

    /**
     * Write the requested changes onto the business.
     *
     * Only inside `approveAmendment`'s transaction, so five requested changes
     * are one write and cannot half-land. Rows already stamped `applied_at` are
     * skipped, which makes this safe to reach twice — a replayed approval
     * writes nothing a second time and, more importantly, does not overwrite
     * `old_value` with the value it already installed.
     *
     * @return list<string> the fields actually written, for the office notice
     */
    private function applyAmendments(Application $app): array
    {
        $business = $app->business;
        if ($business === null) {
            return [];
        }

        $rows = $app->requestedChanges()->whereNull('applied_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $written = [];

        foreach ($rows as $row) {
            if (! AmendableFields::allows($row->field)) {
                // Recorded and left alone rather than dropped in silence: the
                // applicant asked for something this system cannot apply, and
                // the row is the evidence of the asking.
                continue;
            }

            $old = AmendableFields::apply($business, $row->field, $row->new_value);

            /*
             * `applied_at` only where something was actually written.
             *
             * `owner_name` is declared `writes: null` — the permit prints the
             * ACCOUNT holder's name and BPLO moves the account by hand — so
             * stamping it marked a transfer as done while the business still
             * belonged to the previous account. The old value is still
             * recorded: "recorded, pending the transfer" is a real state and
             * a null `applied_at` is how it reads.
             */
            $applied = AmendableFields::writesToRecord($row->field);
            $row->update([
                'old_value' => $old,
                'applied_at' => $applied ? now() : null,
            ]);

            /*
             * Still counted as CHANGED. The offices and the fee both care
             * that an ownership amendment was granted, whoever finishes the
             * paperwork — it is the approval that is the decision.
             */
            $written[] = $row->field;
        }

        $business->save();
        AmendableFields::flush($business);

        Audit::log('business.amended', $business, [
            'application_id' => $app->id,
            'fields' => $written,
        ]);

        return $written;
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
                /*
                 * An AMENDMENT's completion is a different act from a new
                 * filing's or a renewal's, so it gets its own method rather
                 * than a branch inside `approveOverall`. That one mints the
                 * Mayor's Permit; this one changes the register and mints
                 * nothing — see approveAmendment() for why reprinting the
                 * permit is BPLO's decision and not a side effect.
                 */
                ApplicationStatus::ForFinalApproval => $app->application_type === ApplicationType::Amendment
                    ? $this->approveAmendment($app, $remarks)
                    : $this->approveOverall($app, $remarks),
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

    /**
     * An office REFUSED its permit. The third of the officer's three answers.
     *
     * ── Why BPLO cannot reach this ───────────────────────────────────────────
     *
     * BPLO's seat has its own refusal already — `rejectApplication`, which
     * decides the business may not be licensed at all — and it is a different
     * act with a different consequence. Letting BPLO through here would let it
     * suspend a business permit on the strength of refusing the BUSINESS pivot,
     * which is the row that ISSUED that permit: the certificate would be
     * suspended for the absence of itself.
     *
     * So the office boundary is not a policy detail here, it is what keeps the
     * two refusals from meeting. The five clearance offices refuse clearances;
     * BPLO refuses filings.
     */
    public function rejectAssignment(
        ApplicationAssignment $assignment,
        string $reason,
        string $remedy = '',
        /* Which rows the refusal is about; see `rejectClearance`. */
        ?string $target = null,
        /** @var array<string, string> */
        array $notes = [],
    ): void {
        $app = $assignment->application;

        if ($app->status?->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => ['This application has been decided, so its permits can no longer be refused.'],
            ]);
        }

        if ($assignment->department_id === $this->bploDepartmentId()) {
            throw ValidationException::withMessages([
                'permit' => [
                    'BPLO does not refuse a permit from here. Rejecting the whole '
                    .'application is the decision BPLO makes, and it is a different act.',
                ],
            ]);
        }

        $row = $this->pivotForDepartment($app, $assignment->department_id);
        if ($row === null) {
            throw ValidationException::withMessages([
                'permit' => ['This office has no permit to refuse on this application.'],
            ]);
        }

        $this->rejectClearance($row, $reason, $remedy, $target, $notes);
    }

    /** An office returned its queue item. BPLO returns the form; an OP returns its permit. */
    /**
     * @param  array<string, string>  $notes  One remark per returned field —
     *                                        only meaningful on the BPLO main-form branch below, which is the only
     *                                        return that names wizard fields. Passed straight through rather than
     *                                        inspected here: `returnMainForm` owns what a note means.
     */
    /**
     * Change what an already-returned filing is being asked for.
     *
     * `returnAssignment`'s counterpart, and it routes the same way: BPLO
     * naming a clearance amends that clearance's return, everything else
     * amends the main form's. Neither transitions anything — see
     * `amendMainFormReturn` for why that is the whole point.
     *
     * @param  array<string, string>  $notes
     */
    public function amendReturn(
        ApplicationAssignment $assignment,
        string $remarks,
        ?string $target = null,
        array $notes = [],
    ): void {
        $app = $assignment->application;
        $app->loadMissing('permitTypes');

        /*
         * An office amends its OWN permit's return. BPLO has no permit of
         * its own to return, so it lands on the main form unless its
         * pointer names a clearance — the same fork `returnAssignment`
         * makes, for the same reasons, written out there at length.
         */
        if ($assignment->department_id !== $this->bploDepartmentId()) {
            $row = $this->pivotForDepartment($app, $assignment->department_id);
            if ($row !== null) {
                $this->amendClearanceReturn($row, $remarks, $target, $notes);

                return;
            }
        }

        $clearance = $target === null
            ? null
            : $app->permitTypes->firstWhere('code', $target);

        if ($clearance !== null && $clearance->code !== PermitType::OUTCOME_CODE) {
            $row = $this->pivotForDepartment($app, $clearance->issuing_department_id);
            if ($row !== null) {
                $this->amendClearanceReturn($row, $remarks, $target, $notes);

                return;
            }
        }

        $this->amendMainFormReturn($app, $remarks, $target, $notes);
    }

    public function returnAssignment(
        ApplicationAssignment $assignment,
        string $remarks,
        ?string $target = null,
        array $notes = [],
    ): void {
        $app = $assignment->application;
        // The BPLO branch below looks a permit up by code on this collection;
        // `loadMissing` so a caller that did not eager-load gets the rows rather
        // than a silent miss that falls through to a whole-form return.
        $app->loadMissing('permitTypes');

        if ($assignment->department_id === $this->bploDepartmentId()) {
            /*
             * ── BPLO can send back ONE clearance, and the pointer says which ──
             *
             * At Final Approval, BPLO is reading uploaded certificates rather
             * than a form. When one is expired or wrong, returning the whole
             * filing would be wildly disproportionate — a paid, nearly finished
             * renewal sent back to the start of BPLO's own process over one bad
             * scan, and `Returned → ForApproval` is the only way out, so BPLO
             * would then read the form a second time for nothing.
             *
             * Client's decision, 17 September 2026: return that one clearance
             * and keep the filing on BPLO's desk. The applicant re-uploads,
             * `outstandingClearances` stops counting the returned copy as
             * satisfied so Approve stays shut until they do, and the other four
             * copies and the payment are left alone.
             *
             * `$target` is how BPLO names it — the same pointer an office uses
             * to say which checklist row it means, here carrying a permit type
             * code. A target naming nothing on the filing falls through to the
             * whole-form return, which is the safe direction: worst case BPLO
             * gets the behaviour it had before.
             */
            $clearance = $target === null
                ? null
                : $app->permitTypes->firstWhere('code', $target);

            if ($clearance !== null && $clearance->code !== PermitType::OUTCOME_CODE) {
                $row = $this->pivotForDepartment($app, $clearance->issuing_department_id);

                if ($row !== null) {
                    $this->returnClearance($row, $remarks, $target, $notes);

                    return;
                }
            }

            /*
             * Otherwise BPLO returns the whole FORM, which is what its return
             * has always meant before payment: the filing goes to Returned and
             * the wizard reopens. No pointer is stored — there is no clearance
             * row to hang one on, and BPLO's remarks reach the applicant through
             * the filing's own Returned state.
             *
             * The pointer comes too, since 24 September 2026. A target that
             * named a clearance was handled above; anything else is a field on
             * the main form, and it is stored verbatim rather than checked
             * against a list — the valid set is the wizard's own field keys,
             * which live in TypeScript. A code that matches nothing flags
             * nothing, which is the same outcome as sending none.
             *
             * The gap worth naming rather than hiding: an OFFICE that spots a
             * wrong address or line of business still cannot get it fixed this
             * way. `OfficeFormController::ownerMayEdit` only reopens the office
             * form, never sections A–E, so that office has to ask BPLO.
             */
            $this->returnMainForm($app, $remarks, $target, $notes);

            return;
        }

        $row = $this->pivotForDepartment($app, $assignment->department_id);
        if ($row === null) {
            throw ValidationException::withMessages([
                'permit' => ['This office has no permit to return on this application.'],
            ]);
        }

        $this->returnClearance($row, $remarks, $target, $notes);
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
        $this->recordDeferredFee($app, $row->permitType);
        $this->raiseDenrRequirements($app, $row->permitType);

        $this->notify->applicationStatus(
            $app,
            $app->status,
            $row->permitType->name.' has been approved and issued.',
        );

        /*
         * A granted permit may be the one that was holding the business
         * permit suspended. Asked here rather than only on the re-apply path
         * because this is the single place a clearance becomes Approved, so
         * it is the single place the suspension can stop being warranted.
         */
        $this->reconsiderSuspension($app->fresh());

        $this->refreshReadiness($app->fresh());
    }

    /**
     * A permit was issued on a filing that never billed for it. Record the debt.
     *
     * ── When this fires, and when it must not ─────────────────────────────────
     *
     * Client's decision, 17 September 2026: *"The payment for each permit will
     * also happen ONLY WHEN a business permit was renewed on January. So for
     * example, if I renew my sanitary permit, its payment will only reflect once
     * I renew my business permit for the next renewal season."*
     *
     * The condition is not "is it a renewal" and not "is it January". It is
     * whether the filing that issued this permit CHARGED for it, and exactly one
     * shape of filing does not:
     *
     *  - a NEW filing bills everything at submission — nothing deferred.
     *  - a RENEWAL CARRYING THE BUSINESS PERMIT is the January renewal itself.
     *    Its Tax Order of Payment covers its own permits and sweeps in the
     *    outstanding ones; recording a receivable here would put a fee on the
     *    very bill that just collected it.
     *  - a RENEWAL WITHOUT THE BUSINESS PERMIT — a sanitary permit renewed in
     *    June — bills nothing. That is the deferral, and this is the only case
     *    that writes a row.
     *
     * Keyed on the permit set rather than on the calendar deliberately: there is
     * NO January lock ("don't add a lock in our system yet for this"), so a
     * business permit renewal filed in June is still the renewal that collects,
     * and a sanitary renewal filed on 3 January still defers to it.
     *
     * `firstOrCreate` on (application, permit type), matching `issuePermitFor`
     * immediately above. A re-inspection conducted after a permit was already
     * issued calls through here again, and a second row would bill the applicant
     * twice for one certificate.
     */
    private function recordDeferredFee(Application $app, PermitType $type): void
    {
        if ($app->application_type !== ApplicationType::Renewal) {
            return;
        }

        $app->loadMissing('permitTypes');
        $carriesBusinessPermit = $app->permitTypes
            ->contains(fn (PermitType $pt) => $pt->code === PermitType::OUTCOME_CODE);

        if ($carriesBusinessPermit || $app->business_id === null) {
            return;
        }

        /*
         * Priced from this filing's own assessment line for this permit, not
         * recomputed. `assessFees` ran at submission over the revenue-code
         * rules, and re-deriving the number here would let two answers exist
         * for one fee — the one the applicant was shown and the one they are
         * eventually billed.
         */
        $amount = $this->deferredAmountFor($app, $type);
        if ($amount <= 0.0) {
            return;
        }

        $penalty = $this->latePenaltyFor($app, $this->priorPermitFor($app, $type), $amount);

        UnbilledPermitFee::firstOrCreate(
            ['application_id' => $app->id, 'permit_type_id' => $type->id],
            [
                'business_id' => $app->business_id,
                'amount' => $amount,
                'surcharge' => $penalty['surcharge'],
                'interest' => $penalty['interest'],
                'months_late' => $penalty['months_counted'],
                'incurred_at' => now(),
            ],
        );

        Audit::log('permit_fee.deferred', $app, [
            'permit_type' => $type->code,
            'amount' => $amount,
            'surcharge' => $penalty['surcharge'],
            'interest' => $penalty['interest'],
            'months_late' => $penalty['months_counted'],
        ]);

        /*
         * And tell the applicant, now rather than in January.
         *
         * They have just been handed a certificate and asked for no money,
         * which reads as "paid" unless somebody says otherwise. Months later
         * the fee — and the surcharge, if the renewal was late — lands on a
         * bill they had no reason to expect. The rule is right; meeting it
         * for the first time at the counter is what turns it into a
         * complaint.
         *
         * After the audit, and outside it: a notification that fails must not
         * lose the receivable. The debt is the record, the message is a
         * courtesy, and `permitIssuedUnbilled` returns quietly when the
         * permit has no reachable owner.
         */
        $issued = Permit::where('application_id', $app->id)
            ->where('permit_type_id', $type->id)
            ->first();

        if ($issued !== null) {
            $this->notify->permitIssuedUnbilled(
                $issued,
                $amount,
                round($penalty['surcharge'] + $penalty['interest'], 2),
            );
        }
    }

    /**
     * How late this filing was against the permit it renews, priced.
     *
     * Secs. 8A.04 and 8A.05: 25% of the amount due, once, plus 2% a month
     * on fee-plus-surcharge, the interest capped at 36 months. The
     * arithmetic is `FeeCalculator::latePenalty`, which has implemented
     * exactly this since the revenue code was transcribed and which nothing
     * had ever called — the ordinance was in the database and in a unit
     * test, and no bill BizTrack issued carried a peso of it.
     *
     * ── Counted to SUBMISSION, not to issue ────────────────────────────
     *
     * This runs when the permit is issued, which is weeks after the
     * applicant filed: the office has to review the sheet, schedule an
     * inspection and conduct it. Counting to today would charge the
     * applicant 2% a month for the office's own queue, and would make the
     * penalty depend on how busy CHO was — two businesses equally late
     * paying different amounts. `submitted_at` is the moment the applicant
     * did the only thing they control.
     *
     * ── And frozen there ───────────────────────────────────────────────
     *
     * Client, 1 October 2026, choosing between counting to the filing and
     * counting to the January payment: *"Expiry -> filing, frozen."* The
     * months between issue and the January bill add nothing, because that
     * wait is the city's collection scheme rather than the applicant's
     * delay — a clearance renewed out of season is issued unbilled by
     * rule, and billing interest across a deferral nobody asked for would
     * penalise obeying the process.
     *
     * Returns zeros rather than null when nothing is owed, so callers do
     * not each have to decide what an absent penalty looks like.
     *
     * @return array{surcharge: float, interest: float, months_counted: int, total: float}
     */
    private function latePenaltyFor(Application $app, ?Permit $prior, float $amount): array
    {
        $none = ['surcharge' => 0.0, 'interest' => 0.0, 'months_counted' => 0, 'total' => $amount];

        if ($prior?->valid_until === null || $amount <= 0.0) {
            return $none;
        }

        $expired = CarbonImmutable::parse($prior->valid_until)->endOfDay();
        // The pretend date while the Debug page sets one; the real filing time
        // otherwise (BusinessDate). submitted_at itself is never rewritten.
        $filed = BusinessDate::filedAt($app->submitted_at ?? $app->created_at);

        if ($filed->lessThanOrEqualTo($expired)) {
            return $none;
        }

        /*
         * Whole months, rounded UP, so a filing one day past expiry is one
         * month late rather than none. Sec. 8A.05 charges "per month or
         * fraction thereof", which is that rule and not a rounding choice
         * of ours; `diffInMonths` alone would give nought and the surcharge
         * would arrive with no interest beside it for a whole month.
         */
        $months = (int) ceil($expired->floatDiffInMonths($filed));

        return app(FeeCalculator::class)->latePenalty($amount, max(1, $months));
    }

    /**
     * What this filing assessed for one permit type.
     *
     * The assessment stores `line_items` as label/amount pairs rather than a
     * per-permit breakdown, so there is nothing to look up by code. For a
     * clearance-only renewal the whole assessment IS this permit's fee when the
     * filing carries one permit, which is the ordinary case; a filing renewing
     * two clearances at once splits it by the flat schedule, which is the same
     * fallback `assessFees` uses when the revenue-code rules produce nothing.
     *
     * Approximate by construction, and said so rather than hidden: a precise
     * answer needs `fee_assessments.line_items` to carry the permit type it
     * belongs to, which is a schema change worth making when something other
     * than this needs it too.
     */
    private function deferredAmountFor(Application $app, PermitType $type): float
    {
        $assessment = $app->feeAssessment()->first();

        /*
         * ── The bill's own lines for THIS permit, not a second opinion ───────
         *
         * This used to take the whole assessment total when the filing carried
         * exactly one clearance, and otherwise recompute from the flat schedule
         * on `permit_types`. Two schedules, and which one applied depended on
         * how many permits were on the filing — so a Sanitary + FSIC renewal
         * was billed ₱0.00 and stacked ₱1,460 against it (measured
         * 19 September 2026). The single-clearance case agreed only because
         * both paths happened to fall back to the same flat figures.
         *
         * Now the amount carried to January is the amount the applicant was
         * shown, summed from the lines attributed to this permit. The two
         * cannot disagree, because there is only one of them.
         */
        if ($assessment !== null) {
            $lines = collect($assessment->line_items ?? [])
                ->filter(fn ($i) => in_array($type->code, (array) ($i['permit_codes'] ?? []), true));

            if ($lines->isNotEmpty()) {
                return round($lines->sum(fn ($i) => (float) ($i['amount'] ?? 0)), 2);
            }
        }

        /*
         * No bill, or a bill that says nothing about this permit — which is
         * the shape of an assessment written before line items carried a
         * permit code. The flat schedule is the only answer left, and it is
         * what the old code would have given anyway.
         */
        $lineCount = max(1, $app->business?->lines()->count() ?? 1);

        return round((float) $type->base_fee + ((float) $type->per_line_surcharge * $lineCount), 2);
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
        /*
         * ── Never for a business that is suspended or blacklisted ───────────
         *
         * Every certificate passes through here, so this is the last door: an
         * action above that forgot to ask still cannot mint an Active permit
         * that /verify would call valid for a business the super admin has
         * suspended (scenario run, owner-permits-verify 37). Each caller
         * refuses earlier, before it writes anything; this is the backstop.
         */
        $this->refuseWhileOnHold($app->business);

        /*
         * ── A renewal continues the term; it does not restart it ─────────────
         *
         * This dated every permit `now() → now() + validity_days`, and on a
         * renewal that threw away cover the applicant had already paid for. A
         * shop renewing its sanitary permit on 8 September, sixty days before
         * the old one lapsed on 7 November, got a certificate running from the
         * 8th — and lost those sixty days. Renewing early was penalised, which
         * is the opposite of what an LGU wants from a renewal season.
         *
         * So a renewal issued while its predecessor is still valid begins the
         * day after that predecessor ends. A renewal of something already
         * lapsed begins today, because there is no unexpired term to continue
         * and back-dating one would invent cover for a period the business
         * traded without a permit.
         *
         * Client's decision, 9 September 2026, over the calendar-year
         * alternative (1 Jan – 31 Dec, the Mayor's Permit convention). That one
         * was not chosen and is not implemented — it would have changed NEW
         * applications too, and the fee period with them.
         */
        $prior = $this->priorPermitFor($app, $type);
        $priorEnds = $prior?->valid_until ? Carbon::parse($prior->valid_until)->startOfDay() : null;
        $continues = $priorEnds !== null && $priorEnds->greaterThanOrEqualTo(now()->startOfDay());

        $validFrom = $continues ? $priorEnds->copy()->addDay() : now()->startOfDay();

        /*
         * ── The BUSINESS permit ends on 20 January, whatever the start ───────
         *
         * The client's decision of 17 September 2026: *"Business permits always
         * expire on January, regardless of application date."* New filings and
         * renewals alike — so one issued in June 2026 runs to 20 January 2027,
         * a seven-month first term, and one issued that December runs to the
         * same day after a month. `RenewalSeason` carries the date and the
         * ordinance reference.
         *
         * This is the calendar-year convention that was put to the client on
         * 9 September and NOT chosen (see the note above). It is chosen now, and
         * only for this one permit type: the other five renew any time and keep
         * continue-the-term, because anchoring them would penalise renewing
         * early, which is the reasoning of the 9 September note and still holds.
         *
         * `validity_days` is ignored for the business permit and deliberately
         * left on the row at 365. It is what the OTHER branch reads and what a
         * future permit type will read; zeroing it to signal "anchored instead"
         * would make the column mean two things.
         */
        /*
         * ---- Two anchors, and `validity_days` is now read by neither -------
         *
         * The business permit ends on 20 January, per Sec. 2N above. Every
         * other certificate ends on 31 December of the year it was issued:
         * *"ang expiration ay always end of a year, so laging December 31,
         * 202X"* [client, 1 October 2026].
         *
         * That replaces continue-the-term for the five clearances — the
         * `addDays($validityDays)` this used to be — which came from the
         * 9 September reasoning that anchoring punishes renewing early. The
         * client has overruled it for the look of the certificate, and the cost
         * is real and small: a clearance issued in November runs about seven
         * weeks rather than a year.
         *
         * `validity_days` stays on the row, now read by nothing. It is left
         * rather than zeroed for the reason the note below already gives about
         * the business permit: a column that means "the term" on some rows and
         * "ignore me" on others means nothing on any of them, and a future
         * permit type that does run a rolling term will want it back.
         */
        $validUntil = $type->code === PermitType::OUTCOME_CODE
            ? RenewalSeason::endOfTermFor(CarbonImmutable::parse($validFrom))
            : RenewalSeason::endOfCalendarYearFor(CarbonImmutable::parse($validFrom));

        /*
         * Who signs it, with one addition only this moment can make.
         *
         * `officerInChargeFor` answers from the record — the assignment, then
         * the classifier — and is the only thing print time may use. At issue
         * there is one more candidate it cannot see: the person performing the
         * act. An officer approving their office's clearance IS that office's
         * signatory even on a filing nobody formally claimed.
         *
         * Guarded on the department, which is what keeps the Business Permit
         * right: released at payment, its acting user is the applicant, who
         * belongs to no office and so is never written here.
         */
        $acting = Auth::user();
        $officer = PermitFace::officerInChargeFor($app, $type)
            ?? ($acting?->department_id === $type->issuing_department_id ? $acting : null);

        $permit = Permit::firstOrCreate(
            ['application_id' => $app->id, 'permit_type_id' => $type->id],
            [
                'permit_number' => Numbering::permitNumber($type->permit_number_prefix),
                'business_id' => $app->business_id,
                'status' => PermitStatus::Active,
                'valid_from' => $validFrom->toDateString(),
                'valid_until' => $validUntil->toDateString(),
                'issued_at' => now(),
                'issued_by_user_id' => Auth::id(),
                /*
                 * ── The face, frozen at signature ────────────────────────────
                 *
                 * The certificate's printed details were assembled from the
                 * live business record every time the PDF was downloaded, so
                 * editing a business rewrote every permit it had ever held. An
                 * amendment made that concrete: change an address and five
                 * clearances silently began describing premises their offices
                 * had never inspected.
                 *
                 * Captured here, at the one moment a permit is created, and
                 * read back by `PermitFace::forPrinting`. The relations it
                 * needs are loaded explicitly rather than left to lazy loading
                 * — a snapshot that quietly recorded null for the owner's name
                 * because the relation was not on the model would be worse than
                 * no snapshot at all, since it prints as blank on the paper.
                 */
                /*
                 * The signatories are frozen with the rest of the face, and
                 * for the same reason [client, 1 October 2026: put the Mayor
                 * and the officer in charge on every permit].
                 *
                 * A certificate names the people who signed it. Reading them
                 * live would have a new mayor retroactively re-signing every
                 * permit the city has ever issued, and an officer moving office
                 * rewriting the clearances they granted in the old one.
                 *
                 * The officer is resolved above, from the record rather than
                 * from the session — on the Business Permit the acting user is
                 * the applicant who just paid.
                 */
                'issued_details' => PermitFace::capture(
                    $app->business?->loadMissing(['address.barangay', 'owner', 'lines.psicCode'])
                ) + PermitFace::captureSignatories($officer),
            ],
        );

        /*
         * ── And the permit it replaces stops being one the business holds ────
         *
         * Two live certificates of the same type is not a state anybody can act
         * on: the applicant's profile listed both, the renewal picker offered
         * both, and an inspector verifying the business could have been handed
         * either. `Superseded` rather than `Expired` — see the note on the enum
         * for why the true expiry date has to survive.
         *
         * Only when the predecessor is still live. A renewal of a permit that
         * already expired, or was revoked, leaves that status alone: those say
         * something the renewal does not undo.
         */
        if ($prior !== null && $prior->status?->isLive()) {
            $prior->update(['status' => PermitStatus::Superseded]);
            Audit::log('permit.superseded', $prior);
        }

        return $permit;
    }

    /**
     * The permit this filing is renewing FOR THIS PERMIT TYPE, if any.
     *
     * Reads the chain the permit itself records where it can — `Permit::booted`
     * writes `prior_permit_id` at creation and is type-matched — and falls back
     * to the filing's ticked set for the moment before the row exists, which is
     * when the dates above are being computed.
     */
    private function priorPermitFor(Application $app, PermitType $type): ?Permit
    {
        if ($app->application_type !== ApplicationType::Renewal) {
            return null;
        }

        return $app->priorPermits()
            ->where('permits.business_id', $app->business_id)
            ->where('permits.permit_type_id', $type->id)
            ->orderByDesc('permits.valid_until')
            ->first();
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

    /**
     * The office that issues the Business Permit.
     *
     * Public since 27 September 2026: the corrections route has to find BPLO's
     * own assignment to read which fields it ticked when it returned the
     * filing, and re-deriving that from `PermitType::OUTCOME_CODE` in a
     * controller would be a second copy of the one fact this answers.
     */
    public function bploDepartmentId(): ?int
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
    /**
     * Has an officer CONFIRMED this filing's RA 11032 tier?
     *
     * The same question `requireProcessingCategory()` asks, without throwing,
     * because there is now one caller that must not throw. When a new filing's
     * last clearance is approved it issues the business permit on the spot, and
     * that runs inside the CLEARANCE OFFICE's approval — so a filing missing its
     * tier would fail CENRO's own Approve press with a validation error about a
     * category CENRO has no part in setting and cannot fix.
     *
     * The gap should not exist: `approveMainForm()` already refuses BPLO's first
     * act without a confirmed tier, so anything past it has one by construction.
     * One filing in the register is past it without one — written before that
     * guard existed — which is exactly why the auto path tests rather than
     * assumes.
     */
    private function hasProcessingCategory(Application $app): bool
    {
        return Ra11032::isTier($app->complexity) && $app->complexity_set_by_user_id !== null;
    }

    private function requireProcessingCategory(Application $app): void
    {
        if ($this->hasProcessingCategory($app)) {
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

    /**
     * Put a filing back in its office's pool, with nobody holding it.
     *
     * The other end of `assignOfficer`, and the act the Officer in Charge
     * screen could not perform: its dialog had to name a successor, so an
     * office losing its only officer had no way to release the work from
     * there. The caseload screen could do it through `reassign-caseload`,
     * which takes `to_user_id: null` — this is the same meaning on the
     * per-assignment endpoint.
     *
     * `assigned_at` goes with the holder. It records when THIS officer took
     * it, so leaving it behind would date a claim nobody has made, and the
     * office queue orders by longest-waiting.
     *
     * Its own audit action rather than a `reassigned` with a null officer: an
     * auditor reading the trail should not have to inspect the payload to tell
     * a handover from a release.
     */
    public function releaseOfficer(ApplicationAssignment $assignment, ?string $reason = null): void
    {
        if ($assignment->officer_user_id === null) {
            // Already in the pool. Not an error — the reader asked for a state
            // the filing is already in, and saying so would be pedantry.
            return;
        }

        $assignment->forceFill(['officer_user_id' => null, 'assigned_at' => null])->save();

        Audit::log('assignment.released', $assignment, [
            'released_by_user_id' => Auth::id(),
            'reason' => $reason,
        ]);
    }
}
