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
                /*
                 * The id, for re-uploading. `documents.upload` posts a
                 * `document_type_id`, and a returned document is re-submitted
                 * from the applicant's status page — which knows the document
                 * only through this object. The code is what every READER
                 * matches on and stays the identifier; this is for the one
                 * caller that has to write.
                 */
                'id' => $this->documentType->id,
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
