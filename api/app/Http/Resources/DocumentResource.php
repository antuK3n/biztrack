<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches contract document shape (embedded in ApplicationResource). */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->relationLoaded('documentType') && $this->documentType ? [
                'code' => $this->documentType->code,
                'name' => $this->documentType->name,
            ] : null,
            'original_filename' => $this->original_filename,
            'size_bytes' => (int) $this->size_bytes,
            'created_at' => optional($this->created_at)->toISOString(),
            'download_url' => url("/api/v1/documents/{$this->id}/download"),
        ];
    }
}
