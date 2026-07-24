<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Support\Audit;
use App\Support\OcrLite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document uploads (private local disk) + policy-checked downloads.
 */
class DocumentController extends Controller
{
    public function store(Request $request, Application $application): JsonResponse
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );

        $data = $request->validate([
            'document_type_id' => ['required', 'exists:document_types,id'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'file.max' => 'The file may not be larger than 10MB.',
            'file.mimes' => 'Upload a PDF, JPG, or PNG file.',
        ]);

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = "private/documents/{$application->id}/{$filename}";

        Storage::disk('local')->putFileAs(
            "private/documents/{$application->id}",
            $file,
            $filename
        );

        $doc = ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => $data['document_type_id'],
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        Audit::log('document.uploaded', $doc);

        $payload = ['data' => new DocumentResource($doc->load('documentType'))];

        // OCR-lite: parse the PDF text layer for suggestions only (never applied).
        if (str_contains(strtolower((string) $file->getClientMimeType()), 'pdf')
            || strtolower((string) $ext) === 'pdf') {
            $suggestions = OcrLite::extract(Storage::disk('local')->path($path));
            if (! empty($suggestions)) {
                $payload['ocr_suggestions'] = $suggestions;
            }
        }

        return response()->json($payload, 201);
    }

    public function download(Request $request, ApplicationDocument $document): StreamedResponse
    {
        $document->loadMissing('application');
        $app = $document->application;

        $isOwner = $app && $app->applicant_user_id === $request->user()->id;
        $isOfficer = $request->user()->hasPermission('application.view_all');
        abort_unless($isOwner || $isOfficer, 403, 'You may not access this document.');

        abort_unless(Storage::disk('local')->exists($document->stored_path), 404, 'File not found.');

        return Storage::disk('local')->download($document->stored_path, $document->original_filename);
    }
}
