<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recorded status transition (`application_status_history`).
 *
 * WorkflowService::transition() has written one of these on every move since the
 * beginning — 8,628 of them — and until now the only way to read them was
 * `GET /applications/{id}/timeline`, which the applicant's detail page calls and
 * the officer's review sheet did not. The officer sheet already pulls the whole
 * application in one request; making it pay for a second round trip to learn
 * what the office itself did to the filing would have been a strange bargain.
 *
 * So the shape lives here and both readers use it. That is deliberate: the
 * applicant page is typed against this exact object (`TimelineEntry` in
 * web/src/lib/types.ts), and had the endpoint and the resource each grown their
 * own spelling of "who changed it", one of the two screens would have started
 * showing an empty by-line for reasons no one would look for.
 */
class StatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'note' => $this->note,
            // Null is a real answer, not a gap: submission and the
            // payment-triggered routing are transitions the system makes with
            // no officer behind them, and a deleted staff account leaves its
            // history rows standing. Readers render "System" for it.
            'changed_by' => $this->relationLoaded('changedBy') && $this->changedBy
                ? ['name' => $this->changedBy->name]
                : null,
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}
