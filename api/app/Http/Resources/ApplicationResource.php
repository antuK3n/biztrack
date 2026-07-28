<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Full application resource (single-record view). */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking_id' => $this->tracking_id,
            'application_type' => $this->application_type?->value,
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
            'permit_types' => $this->relationLoaded('permitTypes')
                ? $this->permitTypes->map(fn ($pt) => ['id' => $pt->id, 'code' => $pt->code, 'name' => $pt->name])->values()
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
                'line_items' => $this->feeAssessment->line_items,
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
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}
