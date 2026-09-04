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
             * `requires_inspection` is here because the progression rail on the
             * review sheet must not draw a stage this filing will never enter.
             *
             * WorkflowService::afterReviewProgress() checks exactly this flag
             * across exactly these rows: if no chosen permit type requires an
             * inspection, the last office approval goes straight to
             * approveAndIssue(). Without the flag the browser had no way to know
             * that, so the honest options were to always show an inspection step
             * (a lie on roughly half the filings) or never show one (a lie on
             * the rest). The client's own wording is "for those permits that
             * actually has inspection" — this is what makes that answerable.
             */
            'permit_types' => $this->relationLoaded('permitTypes')
                ? $this->permitTypes->map(fn ($pt) => [
                    'id' => $pt->id,
                    'code' => $pt->code,
                    'name' => $pt->name,
                    'requires_inspection' => (bool) $pt->requires_inspection,
                ])->values()
                : [],
            'documents' => $this->relationLoaded('documents')
                ? DocumentResource::collection($this->documents)
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
            'permits' => $this->relationLoaded('permits')
                ? PermitResource::collection($this->permits)
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
