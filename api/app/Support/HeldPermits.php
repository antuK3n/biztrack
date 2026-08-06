<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\PermitType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A clearance the applicant already holds, submitted instead of applied for
 * (tester checklist item 59).
 *
 * The mechanism is deliberately the ordinary document table: the copy is an
 * ApplicationDocument carrying `permit_type_id`, under a document type whose
 * code is HELD_<PERMIT CODE>. What makes it "held" rather than "applied for" is
 * the absence of the permit type from `application_permit_types` — that absence
 * is what spares the applicant the office form, the assignment, and (because
 * FeeCalculator::assess gates every rule on the selected permit types) the fee.
 *
 * Extracted here when the clearance stage moved after payment: two endpoints
 * now accept the same upload — DocumentController for the wizard's existing
 * path, ClearanceController for the post-payment stage — and a second copy of
 * "which document type, and what happens to the previous file" would have been
 * two mechanisms wearing one name.
 */
final class HeldPermits
{
    /** Document-type code prefix for a clearance the applicant already holds. */
    public const CODE_PREFIX = 'HELD_';

    /**
     * The single held copy on this filing for this clearance, if any.
     *
     * The predicate is `permit_type_id`, NOT the HELD_ document type — which is
     * a weaker test than the name suggests, and worth knowing before a third
     * writer of that column appears. Today exactly two paths set it
     * (ClearanceController::storeHeld through `store` below, and
     * DocumentController's `permit_type_id` parameter), both of them under a
     * HELD_ type, so the two predicates select the same rows. `permit_type_id`
     * is the one to keep: it is what makes the copy findable at all, and the
     * document type is derived from it a line further down. A row carrying the
     * column under some other type would be counted as a held copy here — and
     * that is the correct failure, because everything downstream (the card's
     * `submitted` state, the mutual exclusion with applying, PermitController's
     * "your own copy" row) keys on the same column. The name to fix in that
     * case is the other writer's, not this filter.
     */
    public static function find(Application $application, PermitType $permitType): ?ApplicationDocument
    {
        return ApplicationDocument::where('application_id', $application->id)
            ->where('permit_type_id', $permitType->id)
            ->latest('id')
            ->first();
    }

    /**
     * Store the uploaded copy, replacing any earlier one for the same clearance.
     *
     * The path layout matches DocumentController's exactly — private disk,
     * one directory per application, UUID filename — because these files are
     * served by the same download endpoint and an officer reading the
     * attachment list cannot tell (and should not have to) which screen the
     * copy arrived through.
     */
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

        // One certificate per clearance: re-submitting replaces, so the office
        // never has to work out which of two sanitary permits is the live one.
        self::forgetAllExcept($application, $permitType, $doc->id);

        return $doc;
    }

    /**
     * The document type used for a certificate the applicant already holds.
     *
     * Created on demand rather than seeded: the set of clearances is data, so
     * an LGU that adds a permit type gets the matching "already held" slot
     * without a migration. The name carries the permit, which is what makes
     * the attachment readable in an officer's document list.
     */
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

    /**
     * Drop every certificate for this clearance, file and all.
     *
     * The stored file goes with the row: a "removed" document still sitting on
     * disk is not removed, and it stays downloadable through
     * /documents/{id}/download for as long as it is there.
     */
    public static function forget(Application $application, PermitType $permitType): int
    {
        return self::forgetAllExcept($application, $permitType, null);
    }

    /** @param  int|null  $keepId  the row to spare, or null to drop them all */
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
