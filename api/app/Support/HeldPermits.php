<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\PermitType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class HeldPermits
{
    public const CODE_PREFIX = 'HELD_';

    public static function find(Application $application, PermitType $permitType): ?ApplicationDocument
    {
        return ApplicationDocument::where('application_id', $application->id)
            ->where('permit_type_id', $permitType->id)
            ->latest('id')
            ->first();
    }

    public static function store(Application $application, PermitType $permitType, UploadedFile $file): ApplicationDocument
    {
        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $filename = Str::uuid()->toString().'.'.$ext;
        $directory = "private/documents/{$application->id}";

        Storage::disk('local')->putFileAs($directory, $file, $filename);

        $doc = ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => self::documentType($permitType)->id,
            'permit_type_id' => $permitType->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => "{$directory}/{$filename}",
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        Audit::log('document.uploaded', $doc);

        self::forgetAllExcept($application, $permitType, $doc->id);

        return $doc;
    }

    public static function documentType(PermitType $permitType): DocumentType
    {
        return DocumentType::firstOrCreate(
            ['code' => self::CODE_PREFIX.$permitType->code],
            [
                'name' => $permitType->name.' (already held)',
                'help_text' => 'A copy of the '.$permitType->name
                    .' this business already holds, submitted instead of applying for it again.',
            ],
        );
    }

    public static function forget(Application $application, PermitType $permitType): int
    {
        return self::forgetAllExcept($application, $permitType, null);
    }

    public static function forgetAllExcept(Application $application, PermitType $permitType, ?int $keepId): int
    {
        $query = ApplicationDocument::where('application_id', $application->id)
            ->where('permit_type_id', $permitType->id);

        if ($keepId !== null) {
            $query->whereKeyNot($keepId);
        }

        $removed = 0;
        foreach ($query->get() as $old) {
            if ($old->stored_path && Storage::disk('local')->exists($old->stored_path)) {
                Storage::disk('local')->delete($old->stored_path);
            }
            Audit::log('document.removed', $old);
            $old->delete();
            $removed++;
        }

        return $removed;
    }
}
