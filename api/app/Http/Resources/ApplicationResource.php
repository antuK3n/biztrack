<?php

namespace App\Http\Resources;

use App\Enums\ApplicationType;
use App\Support\ApplicationVisibility;
use App\Support\Ra11032;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Full application resource (single-record view). */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'permit_types' => $this->relationLoaded('permitTypes')
                ? $this->permitTypes->map(function ($pt) use ($request) {
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
                     * `remarks` and `rejection_reason` are the other thing
                     * entirely: free prose one office wrote about someone
                     * else's premises. The client's instruction is exact — "the
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
                        'rejection_reason' => $readsWords ? $pt->pivot?->rejection_reason : null,
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
            'office_forms' => $this->relationLoaded('officeForms')
                ? $this->officeForms
                    ->filter(fn ($form) => ApplicationVisibility::readsOfficeSheet(
                        $request->user(),
                        $form->permitType?->issuing_department_id,
                    ))
                    ->map(fn ($form) => [
                        'permit_type_code' => $form->permitType?->code,
                        'permit_type_name' => $form->permitType?->name,
                        'department_code' => $form->permitType?->department?->code,
                        'form_data' => $form->form_data,
                    ])->values()
                : [],
            'fee_assessment' => $this->relationLoaded('feeAssessment') && $this->feeAssessment ? [
                'line_items' => $this->feeLineItems($request),
                'total_amount' => $this->feeAssessment->total_amount,
            ] : null,
            'payments' => $this->relationLoaded('payments')
                ? PaymentResource::collection($this->payments)
                : [],
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
