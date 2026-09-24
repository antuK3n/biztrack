<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PermitStatus;
use App\Exceptions\IllegalTransitionException;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\ApplicationStatusHistory;
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
use App\Support\DenrRequirements;
use App\Support\Numbering;
use App\Support\PermitFace;
use App\Support\Ra11032;
use App\Support\RenewalSeason;
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
             * Only for an address change. Amending a floor area or a trade name
             * tells CPDO nothing it assessed, and attaching zoning to those
             * would be the five-clearance mistake again in miniature.
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

            if (self::amendmentMovesPremises($app)) {
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
         * A NEW filing goes out to the five offices. A RENEWAL and an AMENDMENT
         * both go back to BPLO, for different reasons that happen to need the
         * same status:
         *
         *  - a renewal's certificates are copies BPLO has to read (§5 of
         *    docs/renewal-2026-09-17.md);
         *  - an amendment's last step is on the LGU's own paper, in as many
         *    words: *"AFTER PAYMENT, PLEASE RETURN THIS FORM AND OTHER
         *    REQUIREMENTS TO THE BPLO WINDOW FOR COMPLETION OF PROCESS."* The
         *    counter completes it, and that is also where the register is
         *    changed — so it is a real act rather than a rubber stamp, and not
         *    a candidate for the automatic issuance new filings now get.
         */
        $backToBplo = in_array(
            $app->application_type,
            [ApplicationType::Renewal, ApplicationType::Amendment],
            true,
        );

        $this->transition(
            $app,
            $backToBplo ? ApplicationStatus::ForFinalApproval : ApplicationStatus::AwaitingOtherPermits,
            $backToBplo
                ? 'Payment received. Waiting for BPLO’s final approval.'
                : 'Payment received. You can now apply for the other permits.',
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
             * ── A renewal's uploaded copy routes NOBODY ──────────────────────
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
             * Narrow on purpose. An upload on a NEW filing still routes and is
             * still inspected — `ClearanceService::submitHeld` carries the
             * client's decision of 6 September, *"the LGU inspects the
             * premises, not the paperwork"* — and that stands where it was
             * made. What is new is the renewal case, where the visit behind the
             * certificate already happened.
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

        $resubmitting = $row->status === ClearanceStatus::Returned;
        if (! in_array($row->status, [ClearanceStatus::NotStarted, ClearanceStatus::Returned], true)) {
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
             * This cleared `rejection_reason`, which is gone with the state.
             */
            $row->update([
                'submitted_at' => now(),
                'remarks' => null,
                'remarks_target' => null,
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
    public function returnClearance(ApplicationPermitType $row, string $remarks, ?string $target = null): void
    {
        DB::transaction(function () use ($row, $remarks, $target) {
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
            $this->transitionClearance($row, ClearanceStatus::Returned, $remarks);
            $this->notify->applicationStatus(
                $row->application,
                $row->application->status,
                $row->permitType->name.' was returned for revision: '.$remarks,
            );
        });
    }

    /**
     * ── `rejectClearance()` and `refileClearance()` were here ────────────────
     *
     * An office refusing one permit outright, and the applicant filing for it
     * again. They implemented the client's rule of 6 September 2026 — "only
     * that permit dies" — and neither was ever reachable: no controller, no
     * route, no caller in three weeks. The notification `rejectClearance` sent
     * ended "You can file for it again", pointing at a button nobody built.
     *
     * Removed on 17 September 2026, asked directly and answered: *"I think
     * Return is enough already."* `returnClearance` above does the fixable
     * cases and can repeat as often as needed; a genuinely ineligible business
     * is BPLO's `rejectApplication`, which is a different act on a different
     * object and is kept.
     *
     * `refreshReadiness()` was called from the rejection for a reason worth
     * keeping in view: a permit can be reversed AFTER the filing has reached
     * `for_final_approval`, and without the recheck BPLO would hold an Approve
     * over an application that no longer qualifies. `returnClearance` does not
     * call it — and does not need to, because `ForInspection` and `Approved`
     * can no longer become `Returned`, so a return can only ever happen while
     * the filing is still gathering permits. If a route back from Approved is
     * ever added, that recheck has to come with it.
     */

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
     * ── An uploaded copy on a RENEWAL is satisfied by being uploaded ─────────
     *
     * Client's decision, 17 September 2026, asked directly: on a business permit
     * renewal the applicant uploads certificates the offices have ALREADY
     * issued, and those offices are not involved — no assignment, no form, no
     * inspection. So the requirement is met by the copy being in hand, and the
     * pivot status has nobody left to move it.
     *
     * Deliberately NOT true of a new filing. `ClearanceService::submitHeld`
     * carries the client's decision of 6 September — *"the LGU inspects the
     * premises, not the paperwork, so a business handing in last year's Fire
     * Safety certificate is still visited"* — and that stands where it was
     * made. The narrowing here is to renewals, where the permit being renewed
     * is one the office issued and the visit behind it already happened.
     *
     * An AMENDMENT is treated as a new filing, for the same reason
     * `attachRequiredPermitTypes` leaves it on that path: its shape is an open
     * question the client has said they will take separately, and answering it
     * here by omission would be answering it.
     *
     * @return Collection<int, PermitType>
     */
    public function outstandingClearances(Application $app): Collection
    {
        $renewal = $app->application_type === ApplicationType::Renewal;

        return $app->permitTypes
            ->filter(fn (PermitType $pt) => $pt->isRequiredClearance())
            ->reject(function (PermitType $pt) use ($renewal) {
                if ($pt->pivot->status === ClearanceStatus::Approved) {
                    return true;
                }

                /*
                 * ── A RETURNED upload is not satisfied, whatever its mode ─────
                 *
                 * BPLO reads the uploaded copies at Final Approval and may send
                 * one back — "your FSIC expired in March, upload the current
                 * one" (client's decision, 17 September 2026: return that one
                 * clearance and keep the filing on BPLO's desk).
                 *
                 * Without this exclusion the return would be cosmetic: the
                 * permit would still count as satisfied on the strength of its
                 * mode, and BPLO could approve the renewal on the very copy it
                 * had just rejected. The status is what carries the refusal, so
                 * the status has to be able to override the mode.
                 */
                if ($pt->pivot->status === ClearanceStatus::Returned) {
                    return false;
                }

                return $renewal && $pt->pivot->mode === ApplicationPermitType::MODE_UPLOAD;
            });
    }

    public function refreshReadiness(Application $app): void
    {
        /*
         * ── A clearance-only renewal closes itself ───────────────────────────
         *
         * Client's decision, 17 September 2026: it closes the moment its last
         * permit is issued, with no further press. There is nothing left to
         * decide by then — the office accepted the paperwork and passed the
         * visit, and the certificate is in the applicant's hand. Leaving the
         * FILING at For Initial Approval would have it claim an office was
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
        if ($app->status === ApplicationStatus::ForApproval && $app->defersPayment()) {
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

        if ($ready && $app->status === ApplicationStatus::AwaitingOtherPermits) {
            /*
             * ── A RENEWAL still stops for BPLO; a NEW filing does not ────────
             *
             * Client's decision, 18 September 2026, for the NEW application
             * process only — they were explicit that renewals were not in
             * scope: *"what is the purpose of the BPLO checking if all other
             * permits are legit, when those permits are APPLIED DIRECTLY in
             * BizTrack itself?"*
             *
             * Nothing, on this path. Every clearance was applied for here,
             * approved by its own office here, and inspected against a pivot row
             * here. The stage had no evidence to weigh that the system had not
             * already recorded — and the RA 11032 deadline ran while it waited,
             * so it spent statutory days on a button press.
             *
             * A renewal is the opposite case and keeps the stage: BPLO reads
             * certificate copies the applicant uploaded, which is real evidence
             * of unknown provenance. A renewal only reaches this branch by being
             * walked BACK here from ForFinalApproval (below) when a permit
             * stopped qualifying, so without this test it would silently lose
             * the stage on its way forward again.
             */
            if ($app->application_type === ApplicationType::Renewal) {
                $this->transition(
                    $app,
                    ApplicationStatus::ForFinalApproval,
                    'Every other permit has been approved. Waiting for BPLO’s final approval.',
                );

                return;
            }

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

            $this->approveOverall($app, 'Every clearance approved — business permit issued.');

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
        $this->requireProcessingCategory($app);

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
            $this->transition(
                $app,
                ApplicationStatus::Approved,
                $issuedBusinessPermit
                    ? 'All requirements met. Business permit issued.'
                    : 'All requirements met. Every permit on this application has been issued.',
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
        if ($app->application_type !== ApplicationType::Amendment) {
            throw ValidationException::withMessages([
                'application_type' => ['This is not an amendment, so there are no changes to apply.'],
            ]);
        }

        $this->requireProcessingCategory($app);

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
         * Only an address amendment ever carries a clearance, so for every
         * other kind this is empty and costs nothing.
         */
        $app->load('permitTypes');
        $outstanding = $this->outstandingClearances($app);

        if ($outstanding->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permits' => [
                    'This amendment moves the premises, so it cannot be approved until '
                    .$outstanding->pluck('name')->join(', ')
                    .' has been issued for the new address.',
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
     * The link is Business Owner Status, which is where the transfer lives.
     * Worth saying plainly that it did not exist when this was written — the
     * admin "Reassign" screen moves FILINGS BETWEEN OFFICERS and nothing in
     * the codebase moved a business between owner accounts, so an approved
     * ownership amendment landed nowhere. Transferring one is the other half
     * of this decision, not a nicety.
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
            $row->update(['old_value' => $old, 'applied_at' => now()]);
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

    /** An office returned its queue item. BPLO returns the form; an OP returns its permit. */
    public function returnAssignment(
        ApplicationAssignment $assignment,
        string $remarks,
        ?string $target = null,
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
                    $this->returnClearance($row, $remarks, $target);

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
             * The gap worth naming rather than hiding: an OFFICE that spots a
             * wrong address or line of business still cannot get it fixed this
             * way. `OfficeFormController::ownerMayEdit` only reopens the office
             * form, never sections A–E, so that office has to ask BPLO.
             */
            $this->returnMainForm($app, $remarks);

            return;
        }

        $row = $this->pivotForDepartment($app, $assignment->department_id);
        if ($row === null) {
            throw ValidationException::withMessages([
                'permit' => ['This office has no permit to return on this application.'],
            ]);
        }

        $this->returnClearance($row, $remarks, $target);
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

        UnbilledPermitFee::firstOrCreate(
            ['application_id' => $app->id, 'permit_type_id' => $type->id],
            [
                'business_id' => $app->business_id,
                'amount' => $amount,
                'incurred_at' => now(),
            ],
        );

        Audit::log('permit_fee.deferred', $app, [
            'permit_type' => $type->code,
            'amount' => $amount,
        ]);
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
        $validityDays = (int) ($type->validity_days ?: 365);

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
        $validUntil = $type->code === PermitType::OUTCOME_CODE
            ? RenewalSeason::endOfTermFor(CarbonImmutable::parse($validFrom))
            : CarbonImmutable::parse($validFrom)->addDays($validityDays);

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
                'issued_details' => PermitFace::capture(
                    $app->business?->loadMissing(['address.barangay', 'owner', 'lines.psicCode'])
                ),
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
}
