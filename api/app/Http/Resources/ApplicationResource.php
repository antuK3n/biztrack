<?php

namespace App\Http\Resources;

use App\Enums\ApplicationType;
use App\Enums\OfficerRequestStatus;
use App\Models\ApplicationCorrection;
use App\Models\ApplicationStatusHistory;
use App\Models\PermitType;
use App\Support\AmendableFields;
use App\Support\ApplicationVisibility;
use App\Support\ClearanceStanding;
use App\Support\OfficeFormAnswers;
use App\Support\Ra11032;
use App\Support\ReturnTargets;
use App\Support\SheetRequirements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Full application resource (single-record view). */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * One query for every permit's history, keyed by permit type.
         * Ascending, because a timeline is read from the beginning — the
         * reader that wants the newest first can reverse a short list more
         * cheaply than the API can guess which order a screen wants.
         */
        $historyByPermit = $this->relationLoaded('permitTypes')
            ? ApplicationStatusHistory::query()
                ->where('application_id', $this->id)
                ->whereNotNull('permit_type_id')
                ->with('changedBy:id,name')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->groupBy('permit_type_id')
            : collect();

        /*
         * The FILING's own moves — the rows with no `permit_type_id`, which
         * `transition()` writes and `transitionClearance()` does not.
         *
         * They are merged into the outcome permit's timeline below. Before
         * 28 September 2026 they were read by the application-level
         * `status_history` and by nothing else, so a filing that was
         * returned and resubmitted showed a Mayor's Permit whose history
         * still read "Application submitted" alone.
         */
        $filingHistory = $this->relationLoaded('permitTypes')
            ? ApplicationStatusHistory::query()
                ->where('application_id', $this->id)
                ->whereNull('permit_type_id')
                ->with('changedBy:id,name')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
            : collect();

        return [
            'id' => $this->id,
            'tracking_id' => $this->tracking_id,
            'application_type' => $this->application_type?->value,
            // The applicant's own label for the filing; null means "use the
            // business name", which every reader does.
            'title' => $this->title,
            'payment_mode' => $this->payment_mode,
            /*
             * So a reopened draft can put the tick back. Without it the wizard
             * had no way to know the applicant had already agreed, and asked
             * again every single time — see the note in ApplicationController.
             */
            'data_privacy_consent' => (bool) $this->data_privacy_consent,
            /*
             * The paper form's "Amendment from:" block (checklist items 82/84).
             *
             * Null on a new or renewal filing rather than an object of falses,
             * so the wizard restoring a draft and the officer reading the sheet
             * both get "this form never asked" instead of "asked and answered
             * no" — and neither has to special-case the type to tell them
             * apart.
             */
            'amendments' => in_array(
                $this->application_type,
                [ApplicationType::Amendment, ApplicationType::Renewal],
                true
            ) ? [
                'has_amendments' => (bool) $this->has_amendments,
                'ownership' => (bool) $this->amendment_ownership,
                'location' => (bool) $this->amendment_location,
                'nature' => (bool) $this->amendment_nature,
                'other' => $this->amendment_other,
                /*
                 * Section A3. Null unless A1 was Yes, which is the same "never
                 * asked" vs "asked and answered no" distinction the block above
                 * draws for the type as a whole.
                 */
                'from_registration_type' => $this->amendment_from_registration_type,
                'to_registration_type' => $this->amendment_to_registration_type,
                // Rendered as-is by the officer sheet; built here so the label
                // wording for "Nature of Business" has exactly one home.
                'summary' => $this->resource->amendmentKinds(),
            ] : null,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'business' => $this->whenLoaded('business', fn () => new BusinessResource($this->business)),
            'applicant' => $this->relationLoaded('applicant') && $this->applicant ? [
                'id' => $this->applicant->id,
                'name' => $this->applicant->name,
            ] : null,
            'submitted_at' => optional($this->submitted_at)->toISOString(),
            'deadline_at' => optional($this->deadline_at)->toISOString(),
            'decided_at' => optional($this->decided_at)->toISOString(),
            'ra11032' => $this->ra11032(),
            'rejection_reason' => $this->rejection_reason,
            /*
             * Each requested permit, carrying ITS OWN status.
             *
             * This is the second state machine reaching the browser
             * (docs/application-flow-2026-09.md). The application's `status`
             * above says where the filing is; these say where each permit is,
             * and the two move independently — a filing reading
             * `awaiting_other_permits` can have one permit issued, one being
             * inspected and three not started.
             *
             * `requires_inspection` stays because the progression rail must not
             * draw a stage a permit will never enter: an office whose permit
             * type is desk-only goes straight from For Approval to Approved, and
             * without the flag the browser's honest options were to always show
             * an inspection step or never show one.
             *
             * `is_required` is what the applicant's stage needs to tell a permit
             * they must obtain from one they merely may. Market Clearance is the
             * only false one today, and rendering it identically to the five
             * mandatory ones is how a stall owner would think they were finished
             * — or a shop owner think they were not.
             */
            /*
             * ── Each permit's own status history [26 September 2026] ──────
             *
             * Client: *"put a tracking history PER PERMIT, which contains date
             * and time on when a permit changed status."*
             *
             * Grouped ONCE here rather than queried inside the map. Six permits
             * on a filing would otherwise be six queries, on a payload the
             * applicant's list page already fetches per row — the classic N+1,
             * and it would arrive on the one screen that renders a card for
             * every filing the owner has.
             *
             * `relationLoaded` guards it for the same reason the map below is
             * guarded: the list resource does not load this, and a resource
             * that lazily queries whatever it was not given is how a list
             * endpoint starts issuing a hundred of them.
             */
            'permit_types' => $this->relationLoaded('permitTypes')
                ? $this->permitTypes->map(function ($pt) use ($request, $historyByPermit, $filingHistory) {
                    /*
                     * SEP-5. Progress is shared across the filing; the words an
                     * office wrote are not.
                     *
                     * The same split `readsInspectionDetail` settled for site
                     * visits (INS-8), and it is drawn here for the same reason.
                     * Every office on the filing has a genuine need to know
                     * that the fire permit exists, that it reached inspection
                     * and that it passed — BPLO's final approval is gated on
                     * all five being approved, so an office cannot tell whether
                     * the filing is moving without seeing the others' state.
                     * Withholding status would replace a privacy defect with a
                     * coordination one.
                     *
                     * `remarks` and `remarks_target` are the other thing
                     * entirely: free prose one office wrote about someone
                     * else's premises, and which of their documents it is
                     * about. The client's instruction is exact — "the
                     * City Health Office admin must NOT see any application
                     * fields regarding Fire Safety Inspection Certificate
                     * application" — and this is where that lands on a payload
                     * every office reads.
                     *
                     * `mode` stays shared: apply-or-upload is the shape of the
                     * evidence, not its content, and BPLO's coordination view
                     * would be incoherent without it.
                     */
                    $readsWords = ApplicationVisibility::readsOfficeSheet(
                        $request->user(),
                        $pt->issuing_department_id,
                    );

                    return [
                        'id' => $pt->id,
                        'code' => $pt->code,
                        'name' => $pt->name,
                        'requires_inspection' => (bool) $pt->requires_inspection,
                        'is_required' => $pt->isRequiredClearance(),
                        'status' => $pt->pivot?->status?->value,
                        'status_label' => $pt->pivot?->status?->label(),
                        'mode' => $pt->pivot?->mode,
                        'remarks' => $readsWords ? $pt->pivot?->remarks : null,
                        /*
                         * Which thing the remarks are about — gated with them,
                         * not with the status. On its own the pointer is
                         * harmless ("something about the notarised
                         * declaration"), but paired with an office's own
                         * checklist it narrows what another office asked for,
                         * and the prose and its subject are one disclosure.
                         */
                        'remarks_target' => $readsWords ? $pt->pivot?->remarks_target : null,
                        /*
                         * WHEN it was sent back, shared like every other piece
                         * of progress on this payload. "Waiting on the
                         * applicant for 3 days" is coordination — it is what
                         * lets an office see the filing has gone quiet — and it
                         * says nothing about the content of anyone's remarks.
                         */
                        'returned_at' => optional($pt->pivot?->returned_at)->toISOString(),
                        /*
                         * ── This permit's timeline ───────────────────────
                         *
                         * The STATUS of each move is shared, on the same
                         * reasoning the note at the top of this map sets out
                         * for `status`: every office needs to see that the
                         * fire permit reached inspection and passed, because
                         * BPLO's final approval is gated on all five.
                         *
                         * The NOTE is not. It is free prose one office wrote
                         * about someone else's premises — the same thing
                         * `remarks` above is gated for, and the same gate.
                         * Without this a CHO officer would read BFP's reason
                         * for returning a permit by opening a timeline.
                         */
                        /*
                         * The outcome permit carries the FILING's moves as
                         * well as its own — see the note where
                         * `$filingHistory` is read. Sorted together so a
                         * return and the office decision around it read in
                         * the order they happened rather than in two blocks.
                         */
                        'history' => ($pt->code === PermitType::OUTCOME_CODE
                            ? ($historyByPermit[$pt->id] ?? collect())
                                ->concat($filingHistory)
                                ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                                ->values()
                            : ($historyByPermit[$pt->id] ?? collect()))
                            ->map(fn ($h) => [
                                'from_status' => $h->from_status,
                                'to_status' => $h->to_status,
                                'note' => $readsWords ? $h->note : null,
                                'changed_by' => $h->changedBy?->name,
                                'created_at' => optional($h->created_at)->toISOString(),
                            ])
                            ->values(),
                        'decided_at' => optional($pt->pivot?->decided_at)->toISOString(),
                    ];
                })->values()
                : [],
            /*
             * SEP-8. A shared requirement is everyone's; a permit copy is one
             * office's.
             *
             * Most attachments carry no `permit_type_id` and are exactly what
             * `readsOfficeSheet` calls the applicant's own particulars — the
             * barangay clearance, the lease, the valid ID. Every office on the
             * filing needs those, and this filter must never touch them.
             *
             * The ones that DO carry a permit type are the copies an applicant
             * hands in instead of applying (HeldPermits). Under this flow that
             * is half the process — for each of the five permits the applicant
             * either fills the office's form or uploads the permit they already
             * hold — so a held Fire Safety Inspection Certificate is BFP's
             * evidence as squarely as the FSIC questionnaire is, and the
             * sanitary officer has no more business reading one than the other.
             *
             * Documents were scoped at the FILING level and no finer, which was
             * right while every attachment really was shared. It stopped being
             * enough the moment half the evidence on a filing became
             * office-specific.
             */
            'documents' => $this->relationLoaded('documents')
                ? DocumentResource::collection(
                    $this->documents->filter(fn ($doc) => $doc->permit_type_id === null
                        || ApplicationVisibility::readsOfficeSheet(
                            $request->user(),
                            $doc->permitType?->issuing_department_id,
                        ))->values()
                )
                : [],
            'fee_profile' => $this->fee_profile,
            /*
             * Per-office questionnaires, filtered to the sheets THIS reader
             * owns (SEP-1). See ApplicationVisibility::readsOfficeSheet().
             *
             * This block served every office's answers to every office. The
             * per-office rule was written for `GET /applications/{id}/office-forms`
             * and wired only into OfficeFormController; the officer's review
             * sheet reads office forms from `GET /assignments/{id}`, which
             * resolves this resource, so the one screen the client actually
             * approves filings on was the one screen the fix never reached.
             * Verified before the fix: `sanitary@` on `/assignments/10` received
             * SANITARY and BFP's FSIC; on a seven-office filing the CHO
             * officer's payload carried CENRO's `owner_birthday`.
             *
             * Filtered in the RESOURCE and not at AssignmentController's
             * eager-load, though either would have closed today's leak. The
             * eager-load fix is narrower and that is precisely its weakness: it
             * protects the one caller that exists, and the next caller to write
             * `->load('officeForms')` — a PDF export, a new officer screen —
             * reopens the hole with no test failing. Here, a caller has to load
             * the relation to get anything at all, and whatever it loads is
             * already scoped to the reader.
             *
             * Only office_forms is filtered. Sections A/B/C/E of the sheet — the
             * address, barangay, PSIC line, products, uploaded requirements,
             * floor area — are the applicant's own particulars, shared by every
             * office on the filing because every office needs them to do its
             * job. Do not extend this filter over them.
             */
            /*
             * ── Every form-bearing sheet on the filing, saved or not ─────────
             *
             * This mapped the SAVED `officeForms` rows and nothing else, and the
             * client found what that costs on 9 September 2026: a CENRO session
             * opened a filing it had been routed — its permit applied for, at
             * `for_approval`, its assignment open — and saw the BPLO form and
             * nothing of its own, because the applicant had not yet opened the
             * CEC sheet. No row, no entry, and an office left to infer from an
             * absence whether it was looking at a gap in the paperwork or a bug.
             *
             * `OfficeFormController::index` never behaved that way: it
             * synthesises an entry for every form-bearing permit type on the
             * application, derived answers filled in, precisely "so the wizard
             * can render the derived answers on a form the applicant has not
             * opened yet". So `/office-forms` showed the sheet and
             * `/assignments/{id}` did not — the same filing, two doors, two
             * answers, which is the shape this file has been repaired for four
             * times over (SEP-1, SEP-6, INS-8, and the leak that started it).
             *
             * Both doors now build the list the same way and derive through the
             * same `OfficeFormAnswers`. `form_saved` is what an officer needs on
             * top: a sheet of derived-only answers looks identical whether the
             * applicant filled it in or never touched it, and the screen has to
             * be able to say which.
             *
             * The office boundary is unchanged and still applied per sheet: the
             * applicant sees all, BPLO and the super admin see all, and every
             * other reviewer sees only what its own department issues.
             */
            'office_forms' => $this->relationLoaded('officeForms') && $this->relationLoaded('permitTypes')
                ? $this->permitTypes
                    ->filter(fn ($type) => in_array(
                        $type->code,
                        OfficeFormAnswers::FORM_PERMIT_CODES,
                        true,
                    ))
                    ->filter(fn ($type) => ApplicationVisibility::readsOfficeSheet(
                        $request->user(),
                        $type->issuing_department_id,
                    ))
                    ->map(function ($type) {
                        $stored = $this->officeForms
                            ->first(fn ($form) => $form->permit_type_id === $type->id);

                        return [
                            'permit_type_code' => $type->code,
                            'permit_type_name' => $type->name,
                            'department_code' => $type->department?->code,
                            'form_saved' => $stored !== null,
                            'form_data' => OfficeFormAnswers::derive(
                                $this->resource,
                                $type->code,
                                $stored->form_data ?? [],
                            ),
                            /*
                             * What this office's paper asks the applicant to
                             * bring, and it is on THIS door for the same reason
                             * `form_data` had to be: CPDD is deciding a
                             * locational clearance against a title deed, a tax
                             * declaration and a sketch of the site, and CENRO a
                             * renewal against last year's certificate. A list
                             * the applicant can see and the reviewing office
                             * cannot is half a feature. Both doors, one builder.
                             */
                            'requirements' => SheetRequirements::for($this->resource, $type->code),
                            /*
                             * What the applicant changed on the rows this
                             * office last asked about — was and now.
                             *
                             * Without it a resubmitted sheet is
                             * indistinguishable from the one the office sent
                             * back, which is the complaint that produced the
                             * whole Return feature and was answered for BPLO
                             * on 29 September 2026 and for the offices on the
                             * 30th.
                             *
                             * It includes rows that did NOT change, and that
                             * is the point of it here: the resubmit gate
                             * deliberately lets a file the office called wrong
                             * come back identical — blocking that would trap
                             * an applicant whose document was right — so this
                             * is how the office finds out rather than reading
                             * the document again to discover it.
                             */
                            'corrections' => ApplicationCorrection::where(
                                'application_id',
                                $this->resource->id,
                            )
                                ->where('permit_type_id', $type->id)
                                ->orderByDesc('id')
                                ->get()
                                ->map(fn ($c) => [
                                    'target' => $c->target,
                                    'old_value' => $c->old_value,
                                    'new_value' => $c->new_value,
                                    'at' => optional($c->created_at)->toISOString(),
                                ])->all(),
                        ];
                    })->values()
                : [],
            'fee_assessment' => $this->relationLoaded('feeAssessment') && $this->feeAssessment ? [
                'line_items' => $this->feeLineItems($request),
                'total_amount' => $this->feeAssessment->total_amount,
            ] : null,
            'payments' => $this->relationLoaded('payments')
                ? PaymentResource::collection($this->payments)
                : [],
            /*
             * How many Other Requirements are still open on this filing.
             *
             * A filing does not reach BPLO's final approval while one is
             * (WorkflowService::refreshReadiness), so every screen that shows
             * the stage needs to be able to explain the wait. A filing that
             * stops moving with nothing on it saying why is the defect that
             * rule would otherwise introduce.
             *
             * A COUNT, not the rows: who may read a requirement is the office
             * boundary's question and OfficerRequestController answers it. The
             * number is safe for anyone who may see the filing at all — it says
             * something is outstanding, not what or from whom.
             */
            'open_requirements' => $this->officerRequests()
                // The same two conditions readiness applies, so the number a
                // screen prints and the rule that holds the filing cannot
                // disagree: an OFFICER asked, and it is not settled. A
                // system-raised DENR obligation is due after issuance and
                // belongs in neither.
                ->whereNotNull('requested_by_user_id')
                ->where('status', '!=', OfficerRequestStatus::Fulfilled->value)
                ->count(),
            'assignments' => $this->relationLoaded('assignments')
                ? AssignmentResource::collection($this->assignments)
                : [],
            'inspections' => $this->relationLoaded('inspections')
                ? InspectionResource::collection($this->inspections)
                : [],
            /*
             * SEP-6, and the fourth time this exact door has been left open.
             *
             * `ApplicationVisibility::readsPermitOf` exists precisely so that a
             * sanitary account cannot read a BFP-issued certificate, and it was
             * wired into `PermitController` alone. The officer's review sheet
             * does not call that controller — it reads permits out of
             * `GET /assignments/{id}` and `GET /applications/{id}`, both of
             * which resolve this resource, which had no filter. Same user, same
             * certificate, two endpoints, two answers: 403 on
             * `/permits/{id}`, and the whole certificate here.
             *
             * That is the identical shape as the office-form leak (SEP-1), the
             * permit-list leak the predicate was written for, and the
             * inspection leak (INS-8). Filtering, rather than 403-ing the whole
             * filing, because every office on it is legitimately reading the
             * filing — it is one embedded collection that is not theirs.
             */
            'permits' => $this->relationLoaded('permits')
                ? PermitResource::collection(
                    $this->permits->filter(fn ($permit) => ApplicationVisibility::readsPermitOf(
                        $request->user(),
                        $permit->permitType?->issuing_department_id,
                    ))->values()
                )
                : [],
            /*
             * ── Where the BUSINESS stands on all five clearances ─────────────
             *
             * Not the same list as `permits` above, and deliberately so: that
             * one is what THIS filing issued, this one is what the business
             * holds. On a January renewal of the business permit alone they
             * barely overlap, and it is this list that answers the question the
             * client says Final Approval exists to ask. See ClearanceStanding.
             *
             * Gated on `readsEveryOffice`, which is BPLO and the super admin.
             * This is a cross-office view by construction — five offices'
             * certificates in one array — so handing it to CHO would undo the
             * separability the four filters above this line exist to keep, and
             * it is the fifth time that door has needed closing on this
             * resource. A clearance office reading its own filing gets null,
             * not a shorter list: one office's standing on its own permit is
             * already on its sheet, and a one-row version of this would invite
             * a reader to treat it as the whole answer.
             *
             * Only when `permitTypes` is loaded, because ClearanceStanding asks
             * it which types are on the filing and an unloaded relation would
             * answer "none" — reporting every clearance as relied-upon rather
             * than being renewed.
             */
            /*
             * ── What this amendment asks to change ───────────────────────────
             *
             * The officer's half of `AmendmentController::index`, which is
             * owner-only. BPLO has to read old → new to decide, and the
             * applicant's endpoint cannot serve them: it authorises on
             * `applicant_user_id`.
             *
             * `current_value` is the register as it stands, so the panel can
             * show what is being replaced without the browser holding a second
             * copy of the business record to diff against. After approval
             * `old_value` and `applied_at` are the record of what actually
             * happened, which is why both sides are sent rather than just the
             * request.
             *
             * Null rather than `[]` when there is nothing to send, so a reader
             * can tell "not an amendment" from "an amendment asking for
             * nothing" — the second is a filing BPLO should refuse.
             */
            'requested_changes' => $this->relationLoaded('requestedChanges')
                && $this->application_type === ApplicationType::Amendment
                    ? $this->requestedChanges->map(function ($row) {
                        $current = AmendableFields::current($this->business, $row->field);

                        return [
                            'field' => $row->field,
                            'label' => $row->label(),
                            'current_value' => $current,
                            'new_value' => $row->new_value,
                            'old_value' => $row->old_value,
                            'applied_at' => $row->applied_at?->toISOString(),
                            /*
                             * A line of business and a barangay are ids since
                             * FO-003 was mapped properly, and this panel is
                             * where BPLO decides. Without these it would read
                             * "Change of line of business: 1 → 47" — the same
                             * resolver the applicant's step uses, so the two
                             * screens cannot name the same code differently.
                             */
                            'current_label' => AmendableFields::describe($row->field, $current),
                            'new_label' => AmendableFields::describe($row->field, $row->new_value),
                            'old_label' => AmendableFields::describe($row->field, $row->old_value),
                        ];
                    })->values()
                    : null,
            'clearance_standing' => $this->relationLoaded('permitTypes')
                && $request->user()
                && ApplicationVisibility::readsEveryOffice($request->user())
                    ? ClearanceStanding::forApplication($this->resource)
                    : null,
            /*
             * What actually happened to this filing, oldest first (the relation
             * orders by created_at).
             *
             * Only when eager-loaded. The officer review sheet wants it and asks
             * for it; the list and create responses reuse this same resource and
             * have no use for a filing's whole transition log, so they must not
             * silently pay for one query per row to carry it.
             *
             * An empty array and a not-loaded relation are deliberately the same
             * answer here. A caller that did not ask has no history to show, and
             * a filing genuinely has none until it leaves Draft — neither reader
             * has anything different to do about the two cases.
             */
            'status_history' => $this->relationLoaded('statusHistory')
                ? StatusHistoryResource::collection($this->statusHistory)
                : [],
            /*
             * ── What the applicant put right, and what it was ───────────
             *
             * Client, 27 September 2026: *"where can the admin see the newly
             * complied fields?"* Nowhere, before this — a resubmitted filing
             * arrived looking like any other and the officer re-read fifty
             * questions to find the three they had asked about.
             *
             * Same rule as `status_history` directly above: an unloaded
             * relation and a filing that was never returned are the same
             * empty answer, and no reader has anything different to do
             * about the two.
             */
            /*
             * BPLO's remark for each returned field, keyed by the same code
             * the pointer uses. A map rather than a list because every
             * reader wants it BY FIELD — the applicant drawing a box, the
             * officer reading back what they asked — and a list would make
             * all of them build the same index.
             */
            /*
             * The MAIN FORM's notes only, on the same reasoning as
             * `corrections` below — and missed when that filter was added.
             *
             * The offices record theirs in the same table since 30 September
             * 2026, marked with the permit they belong to; BPLO's carry a
             * null. Unfiltered, a filing whose zoning sheet had been returned
             * handed the main form notes keyed `ZONING_REQ_TAX_DECLARATION` —
             * harmless only because no main-form target is spelled that way,
             * which is luck rather than a rule.
             */
            'return_notes' => $this->relationLoaded('returnNotes')
                ? $this->returnNotes
                    ->whereNull('permit_type_id')
                    ->pluck('note', 'target')
                    ->all()
                : (object) [],
            /*
             * The MAIN FORM's corrections only.
             *
             * Since 30 September 2026 the offices record theirs in the same
             * table, marked with the permit they belong to; BPLO's carry a
             * null. Unfiltered, this block would hand the officer's sheet
             * rows like `ZONING_LEASE_TITLE` and get away with it only
             * because `mainFormTargetLabel` skips codes it does not know.
             * An office's are on its own clearance row.
             */
            'corrections' => $this->relationLoaded('corrections')
                ? $this->corrections->whereNull('permit_type_id')->values()->map(fn ($c) => [
                    'target' => $c->target,
                    'old_value' => $c->old_value,
                    'new_value' => $c->new_value,
                    'at' => optional($c->created_at)->toISOString(),
                ])->all()
                : [],
            /*
             * ── What an officer may return this filing ABOUT ────────────
             *
             * The `form:` codes the applicant actually answered. A field
             * they left blank was never their answer to correct, and a
             * missing TIN already has its own route — see
             * `ReturnTargets::answeredBy`.
             *
             * Sent rather than worked out in the browser because only this
             * side knows which record holds each field. The picker keeps
             * the labels and the grouping, which is what it knows.
             */
            'answered_targets' => $this->relationLoaded('business') && $this->business
                ? ReturnTargets::answeredBy($this->business)
                : [],
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }

    /**
     * The filing's RA 11032 standing: which tier, who said so, and the three
     * tiers anyone is allowed to choose between.
     *
     * ── Why `source` is here and not inferred ─────────────────────────────────
     *
     * The officer's question on the review sheet is not "what tier is this",
     * it is "am I overriding a guess or filling in a blank". Every tier in the
     * register today came from `Ra11032::tierFor()`, a rule this project wrote
     * and the LGU never approved (open question A10) — so `automatic` is not a
     * neutral default, it is a claim the officer is entitled to disagree with,
     * and a payload that only sent the tier would hide that entirely.
     *
     *  - `automatic` — classified at submission by our rule.
     *  - `officer`   — a named person decided it, and `set_by` says who.
     *  - `null`      — never classified at all (a draft, or a row that predates
     *                  the tier being set on submission). Genuinely blank, and
     *                  the sheet says so rather than showing a tier nobody set.
     *
     * ── Why the tier LIST travels with the record ─────────────────────────────
     *
     * So the browser never holds its own copy of the statute. The three tiers
     * and their day counts are RA 11032; a control that hard-coded them could
     * drift into offering a fourth, or into captioning "Simple" with the wrong
     * number of days, and either would be a compliance defect dressed as a
     * typo. Three fixed entries on a single-record payload is a cheap price for
     * the browser being structurally unable to invent one.
     *
     * `editable` is the terminal-filing rule (WorkflowService::classify refuses
     * one), sent rather than re-derived: the screen and the API must not
     * disagree about whether a decided filing can be reclassified.
     *
     * @return array<string, mixed>
     */
    private function ra11032(): array
    {
        $tier = $this->complexity;
        $setBy = $this->relationLoaded('complexitySetBy') ? $this->complexitySetBy : null;

        return [
            'tier' => $tier,
            'label' => Ra11032::label($tier),
            'statutory_working_days' => $tier === null ? null : Ra11032::statutoryWorkingDays($tier),
            'source' => $tier === null
                ? null
                : ($this->complexity_set_by_user_id === null ? 'automatic' : 'officer'),
            /*
             * The name only when the relation was eager-loaded. A list or a
             * create response has no use for it and must not pay a query per
             * row to carry it; the review sheet asks for it and gets it.
             */
            'set_by' => $setBy ? ['id' => $setBy->id, 'name' => $setBy->name] : null,
            'set_at' => optional($this->complexity_set_at)->toISOString(),
            'editable' => ! (bool) $this->status?->isTerminal(),
            'tiers' => Ra11032::tierOptions(),
        ];
    }

    /**
     * Fee lines, with the revenue-code citations stripped for applicants.
     *
     * Officers need `Sec. 3A.02 · A10-2016` to defend an assessment; an
     * applicant reading their bill does not, and the LGU asked that ordinance
     * sections not be surfaced to the public. The UI already hides them, but
     * leaving them in the payload just moves the leak to the network tab.
     */
    private function feeLineItems(Request $request): array
    {
        $items = $this->feeAssessment->line_items ?? [];

        if ($request->user()?->hasPermission('application.review')) {
            return $items;
        }

        return array_map(
            fn ($item) => is_array($item) ? Arr::except($item, ['section', 'source']) : $item,
            $items,
        );
    }
}
