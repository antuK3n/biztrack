<?php

namespace App\Http\Resources;

use App\Enums\ApplicationType;
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
            'amendments' => $this->application_type === ApplicationType::Amendment ? [
                'has_amendments' => (bool) $this->has_amendments,
                'ownership' => (bool) $this->amendment_ownership,
                'location' => (bool) $this->amendment_location,
                'nature' => (bool) $this->amendment_nature,
                'other' => $this->amendment_other,
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
            'office_forms' => $this->relationLoaded('officeForms')
                ? $this->officeForms->map(fn ($form) => [
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
