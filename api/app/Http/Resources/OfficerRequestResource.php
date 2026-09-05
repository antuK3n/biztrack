<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches contract officer-request shape. */
class OfficerRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creator = $this->relationLoaded('createdBy') ? $this->createdBy : null;

        return [
            'id' => $this->id,
            'request_type' => $this->request_type,
            // Emit BOTH the paper names (title/description) and the client-facing
            // legacy names (subject/body) so web/mobile keep working.
            'title' => $this->title,
            'subject' => $this->title,
            'description' => $this->description,
            'body' => $this->description,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            /*
             * The office's reason, and whether the applicant can still act.
             *
             * `remarks` was stored on the model and never returned, so a
             * requirement sent back said only that it had been — the applicant
             * saw "Rejected" and no explanation anywhere in the app. Without
             * `accepts_response` the client has to re-derive the lifecycle from
             * the status string, which is how a Resubmit button ends up offered
             * on a requirement the API will refuse.
             */
            'remarks' => $this->remarks,
            'accepts_response' => (bool) $this->status?->acceptsResponse(),
            'due_date' => optional($this->due_date)->toISOString(),
            'created_by' => $creator ? [
                'name' => $creator->name,
                'department' => $creator->relationLoaded('department') && $creator->department
                    ? $creator->department->name
                    : null,
            ] : null,
            /*
             * The office the request is FROM, as chosen in the composer.
             *
             * `created_by.department` is the requester's own office and stays
             * that, because that is what its name says. The two are not the same
             * thing: the super admin belongs to no office and has to pick one,
             * and an officer may raise a requirement on another office's behalf.
             * The composer has stored `department_id` since checklist item 57
             * but nothing ever read it back — the picker moved a column no
             * screen displayed, which is a control that looks like it does
             * something it does not.
             */
            'from_office' => $this->relationLoaded('department') && $this->department ? [
                'id' => $this->department->id,
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            /*
             * Who receives this (checklist item 89). It is always the applicant
             * on the filing, and it is derived rather than stored because the
             * model has no second recipient to offer: a request is answered
             * through `POST /requests/{id}/respond`, gated on `request.respond`,
             * which only the business_owner role holds — and `index()` hands an
             * owner the requests on their own applications. So this names the
             * one recipient there is rather than implying a choice that the
             * schema cannot honour.
             */
            'recipient' => $this->relationLoaded('application')
                && $this->application?->relationLoaded('applicant')
                && $this->application->applicant
                ? [
                    'id' => $this->application->applicant->id,
                    'name' => $this->application->applicant->name,
                    'kind' => 'applicant',
                ]
                : null,
            'application' => $this->relationLoaded('application') && $this->application ? [
                'id' => $this->application->id,
                'tracking_id' => $this->application->tracking_id,
                'business_name' => $this->application->relationLoaded('business') && $this->application->business
                    ? $this->application->business->name
                    : null,
            ] : null,
            // Latest applicant response (paper: applicant_response; legacy: response_body).
            // Kept for mobile/contract clients; `responses` is the full thread.
            'applicant_response' => $this->applicant_response,
            'response_body' => $this->applicant_response,
            // Every applicant reply, oldest first.
            'responses' => $this->relationLoaded('responses')
                ? $this->responses->map(fn ($r) => [
                    'id' => $r->id,
                    'body' => $r->body,
                    'author' => [
                        'name' => $r->relationLoaded('author') && $r->author ? $r->author->name : null,
                    ],
                    'document' => $r->application_document_id ? [
                        'id' => $r->application_document_id,
                        'filename' => $r->file_name,
                    ] : null,
                    'created_at' => optional($r->created_at)->toISOString(),
                ])->all()
                : [],
            // Meeting fields (officer-provided; calendar integration is future work).
            'meeting_scheduled_at' => optional($this->meeting_scheduled_at)->toISOString(),
            'meeting_duration_minutes' => $this->meeting_duration_minutes,
            'meeting_link' => $this->meeting_link,
            'meeting_platform' => $this->meeting_platform,
            'created_at' => optional($this->created_at)->toISOString(),
            // Applicant-responded timestamp (paper: submitted_at; legacy: responded_at).
            'submitted_at' => optional($this->submitted_at)->toISOString(),
            'responded_at' => optional($this->submitted_at)->toISOString(),
            'reviewed_at' => optional($this->reviewed_at)->toISOString(),
        ];
    }
}
