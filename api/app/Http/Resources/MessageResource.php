<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A single message in one office's conversation on an application. */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            /*
             * Which office this turn belongs to.
             *
             * A thread is scoped to `(application, department)` now, so a
             * message HAS an addressee — and a reader who asked for the whole
             * filing rather than one office gets several conversations merged
             * in time order. Without this the merged view would be exactly the
             * ambiguity the old shared thread had: turns from three offices,
             * indistinguishable except by guessing at the sender's employer.
             *
             * Null only while the relation was not loaded, never because the
             * message has no office: the column is backfilled and
             * MessageThread::booted() defaults it.
             */
            'department' => $this->relationLoaded('thread') && $this->thread?->relationLoaded('department')
                && $this->thread->department
                ? [
                    'id' => $this->thread->department->id,
                    'code' => $this->thread->department->code,
                    'name' => $this->thread->department->name,
                ]
                : null,
            'sender' => $this->relationLoaded('sender') && $this->sender ? [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
                'is_officer' => (bool) $this->sender->department_id,
            ] : null,
            'attachments' => $this->relationLoaded('attachments')
                ? $this->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'original_filename' => $a->original_filename,
                    'download_url' => url("/api/v1/message-attachments/{$a->id}/download"),
                ])->values()
                : [],
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}
