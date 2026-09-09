<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\Permit;

/**
 * MCG-CENRO-FO-001 v2.0's "REQUIREMENTS FOR APPLICATION", the FOR RENEWAL row.
 *
 * ── One row, and only on a renewal ────────────────────────────────────────
 *
 * The paper's requirements box lists four things:
 *
 *     [ ] Application for Renewal / New Business
 *     [ ] Tax Order of Payment and Official Receipt
 *     [ ] Business permit
 *     [ ] Certificate of Environmental Compliance (Previous Year)
 *         FOR RENEWAL
 *
 * Three of them were deliberately dropped from the screen, and the client is
 * the one who dropped them: *"the official receipt can also be seen by the
 * CENRO admin too, so submitting it would just be useless."* The same is true
 * of the application form and the business permit — CENRO opens the filing and
 * they are all on it. Asking an applicant to upload a document the reviewing
 * office is already looking at is work with no reader.
 *
 * The fourth is different, and it is different for exactly one reason: last
 * year's CEC may not be in BizTrack at all. In year one almost every renewal is
 * of a certificate issued on paper, across a counter, before this system
 * existed — so there is nothing for CENRO to open. That is the row worth
 * asking for, and only when the filing is a renewal, because a first-time
 * applicant has no previous year.
 *
 * ── It is CARRIED when the system issued it ───────────────────────────────
 *
 * A business renewing a CEC that BizTrack issued does not upload anything: the
 * certificate is a `permits` row, CENRO can open it, and the row is satisfied
 * by naming it. The upload slot appears only when the register holds no prior
 * CEC for this business — the paper case. Same rule as the zoning checklist's
 * carried rows, same reason: never ask twice for something already held.
 */
final class CecRequirements
{
    /** Document-type code for the previous year's certificate, uploaded. */
    public const PREVIOUS_CEC = 'CEC_REQ_PREVIOUS';

    /**
     * The checklist for this filing. Empty on a new application.
     *
     * @return list<array<string, mixed>>
     */
    public static function forApplication(Application $application): array
    {
        // "FOR RENEWAL", in the paper's own words. An initial application has
        // no previous year, so the box is not merely unticked — it is not asked.
        if ($application->application_type !== ApplicationType::Renewal) {
            return [];
        }

        $held = self::priorCertificate($application);
        $uploaded = self::upload($application);

        return [[
            'key' => 'PREVIOUS_CEC',
            'code' => $held !== null ? null : self::PREVIOUS_CEC,
            'label' => 'Certificate of Environmental Compliance (Previous Year)',
            'note' => $held !== null
                ? 'Already on file — CENRO issued it through BizTrack and can open it from this application.'
                : 'The CEC you are renewing. Upload a scan of last year’s certificate; CENRO has no copy of one issued on paper.',
            'source' => $held !== null ? 'carried' : 'upload',
            'satisfied' => $held !== null || $uploaded !== null,
            'document' => $uploaded,
            /*
             * The certificate number, when the system holds it. Not a document
             * row — a permit is not an attachment — so the screen prints the
             * number rather than offering a file to open, and CENRO reads the
             * permit itself off the filing.
             */
            'reference' => $held?->permit_number,
        ]];
    }

    /** Is `$code` this sheet's one slot? */
    public static function accepts(string $code): bool
    {
        return $code === self::PREVIOUS_CEC;
    }

    /** The document type behind that slot, created on demand. */
    public static function documentType(string $code): DocumentType
    {
        return DocumentType::firstOrCreate(
            ['code' => self::PREVIOUS_CEC],
            [
                'name' => 'Certificate of Environmental Compliance (Previous Year)',
                'help_text' => 'Last year’s CEC, for a renewal. Only needed when the certificate was issued on paper rather than through BizTrack.',
            ],
        );
    }

    /**
     * The CEC this business already holds, if the register issued one.
     *
     * Read off the BUSINESS rather than off `prior_permits`, deliberately. The
     * applicant ticks which permits they are renewing and may well be renewing
     * the CEC as one of several — but they may also be renewing something else
     * entirely while still needing to show CENRO last year's certificate, and a
     * certificate the register holds is a certificate CENRO can open either way.
     * Keying on the tick would ask a business for a scan of a document sitting
     * in the same database.
     */
    private static function priorCertificate(Application $application): ?Permit
    {
        if ($application->business_id === null) {
            return null;
        }

        return Permit::where('business_id', $application->business_id)
            ->whereHas('permitType', fn ($q) => $q->where('code', 'CEC'))
            // Not this filing's own, which does not exist yet on a renewal in
            // flight but would once it is approved — and a certificate issued
            // BY this application is not the previous year's.
            ->where('application_id', '!=', $application->id)
            ->latest('issued_at')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private static function upload(Application $application): ?array
    {
        $document = ApplicationDocument::whereHas(
            'documentType',
            fn ($q) => $q->where('code', self::PREVIOUS_CEC),
        )
            ->where('application_id', $application->id)
            ->latest('id')
            ->first();

        return $document === null ? null : [
            'id' => $document->id,
            'filename' => $document->original_filename,
            'size_bytes' => $document->size_bytes,
            'uploaded_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
