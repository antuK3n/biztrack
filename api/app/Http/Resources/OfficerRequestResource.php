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
            'due_date' => optional($this->due_date)->toISOString(),
            'created_by' => $creator ? [
                'name' => $creator->name,
                'department' => $creator->relationLoaded('department') && $creator->department
                    ? $creator->department->name
                    : null,
            ] : null,
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
