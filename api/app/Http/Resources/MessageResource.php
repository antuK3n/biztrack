<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A single message in an application thread. */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
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
