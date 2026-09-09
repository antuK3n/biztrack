<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOfficeForm;
use App\Models\DocumentType;

/**
 * The CHECKLIST OF REQUIREMENTS printed on MCG-CPDD-FO-003 v1.2, answered for
 * one filing.
 *
 * ── The rules, from the paper ──────────────────────────────────────────────
 *
 * The sheet's checklist is not one list. It branches on how the applicant holds
 * the site, which is the one thing the form asks that BizTrack has always known
 * and never used here:
 *
 *   IF THE PROPERTY IS OWNED   — Transfer Certificate of Title, Tax Declaration,
 *                                Real Property Tax Clearance
 *   IF THE PROPERTY IS RENTED  — Contract of Lease, Consent from the Lot Owner
 *   ALWAYS                     — DTI / SEC Articles, the completed application
 *                                form, the Sketch of the Location, and the
 *                                notarised Applicant Declaration
 *   IF A REPRESENTATIVE FILES  — an Authorization Letter
 *
 * `businesses.is_rented` is the answer, collected on Location & Zoning, and the
 * branch is taken from it rather than asked again — printing both columns and
 * letting the applicant pick would be asking a question the filing has already
 * answered, and inviting a second answer to it. An owner never sees the lease
 * rows; a lessee never sees the title rows.
 *
 * ── What is NOT collected twice ────────────────────────────────────────────
 *
 * Three of the paper's items are already on the filing by the time the
 * applicant reaches this sheet, and they are shown as satisfied rather than
 * asked for again:
 *
 *  - DTI / SEC Articles is `DTI_SEC_CDA`, mandatory on every business permit
 *    filing at step 4 of the wizard.
 *  - The title (owned) and the lease (rented) are both `LEASE_TITLE`, whose
 *    name is literally "Lease Contract or Land Title" — the document type is
 *    already the same branch this checklist makes, and BPLO requires it of
 *    everyone.
 *  - "Completely filled-up the application form" is THIS sheet. It is ticked
 *    when the sheet has been submitted, and nothing is uploaded for it.
 *
 * ASSUMPTION, recorded because it is the one judgement call in the mapping:
 * that CPDD's "Transfer Certificate of Title" and BPLO's "Land Title" are the
 * same piece of paper, and its "Contract of Lease" and BPLO's "Lease Contract"
 * likewise. If CPDD wants its own copy regardless — offices do sometimes keep
 * separate files — these two become upload slots like the rest, which is a
 * one-line change to `carriedFrom` below. See questions-for-malabon C9 item 4,
 * which asked this and has not been answered.
 *
 * ── Nothing here blocks ────────────────────────────────────────────────────
 *
 * Every row is advisory. The paper is a counter checklist that a clerk ticks on
 * receipt, not a gate, and the six DENR permits set the precedent already: what
 * the system can usefully do is tell the applicant what CPDD will look for
 * before they submit. Making the sheet refuse to submit without a notarised
 * declaration would strand every applicant who has not been to a notary yet —
 * and the notarisation itself is still an open policy question
 * (questions-for-malabon C9 item 2).
 */
final class ZoningRequirements
{
    /** Document-type code prefix for a file this checklist collects. */
    public const CODE_PREFIX = 'ZONING_REQ_';

    /**
     * The rows, in the paper's own order.
     *
     * `when` is the branch: 'always', 'owned', 'rented' or 'representative'.
     * `carried_from` names an existing document type that already answers the
     * row, and 'sheet' means the sheet itself answers it — either way nothing
     * is uploaded. Everything else takes a file, under CODE_PREFIX.<key>.
     *
     * @var list<array{key: string, label: string, when: string, note: string, carried_from: ?string}>
     */
    private const ROWS = [
        [
            'key' => 'FORM',
            'label' => 'Completely filled-up application form',
            'when' => 'always',
            'note' => 'This sheet. It is ticked when you submit it.',
            'carried_from' => 'sheet',
        ],
        [
            'key' => 'TCT',
            'label' => 'Transfer Certificate of Title (TCT)',
            'when' => 'owned',
            'note' => 'The land title for the property, which you attached with your business permit documents.',
            'carried_from' => 'LEASE_TITLE',
        ],
        [
            'key' => 'TAX_DECLARATION',
            'label' => 'Tax Declaration',
            'when' => 'owned',
            'note' => 'The current tax declaration for the land or the building, from the City Assessor.',
            'carried_from' => null,
        ],
        [
            'key' => 'RPT_CLEARANCE',
            'label' => 'Real Property Tax Clearance',
            'when' => 'owned',
            'note' => 'Proof the real property tax on the site is paid up, from the City Treasurer.',
            'carried_from' => null,
        ],
        [
            'key' => 'LEASE',
            'label' => 'Contract of Lease',
            'when' => 'rented',
            'note' => 'Your lease over the premises, which you attached with your business permit documents.',
            'carried_from' => 'LEASE_TITLE',
        ],
        [
            'key' => 'LOT_OWNER_CONSENT',
            'label' => 'Consent from the Lot Owner',
            'when' => 'rented',
            'note' => 'A letter from the owner of the lot agreeing to the business being run there. CPDD asks for this in addition to the lease.',
            'carried_from' => null,
        ],
        [
            'key' => 'DTI_SEC',
            'label' => 'DTI / SEC Articles of Incorporation',
            'when' => 'always',
            'note' => 'Your business registration, which you attached with your business permit documents.',
            'carried_from' => 'DTI_SEC_CDA',
        ],
        [
            'key' => 'SKETCH',
            'label' => 'Sketch of the Location',
            'when' => 'always',
            'note' => 'A hand-drawn map of how to reach the site, showing street names and nearby landmarks. Draw it, photograph it, and upload the photo.',
            'carried_from' => null,
        ],
        [
            'key' => 'DECLARATION',
            'label' => 'Applicant Declaration, notarised',
            'when' => 'always',
            'note' => 'Download the template below, sign it before a notary, then upload the scan. The paper says it MUST BE NOTARIZED PRIOR TO SUBMISSION OF APPLICATION.',
            'carried_from' => null,
        ],
        [
            'key' => 'AUTHORIZATION',
            'label' => 'Authorization Letter',
            'when' => 'representative',
            'note' => 'Asked only because you named an authorised representative on item IX. It lets them file and collect on your behalf.',
            'carried_from' => null,
        ],
    ];

    /**
     * The checklist for this filing: which rows apply, and what answers each.
     *
     * @return list<array<string, mixed>>
     */
    public static function forApplication(Application $application): array
    {
        $application->loadMissing('business');

        /*
         * A soft-deleted business leaves the tenure question genuinely
         * unanswered, and the honest reading of an unanswered question is not
         * "owned". Both branches are shown in that case so the applicant can
         * see the whole checklist rather than half of one chosen for them.
         */
        $tenureKnown = $application->business !== null;
        $rented = (bool) $application->business?->is_rented;

        /*
         * Item IX, read through `derive` rather than off the stored sheet.
         *
         * The zoning sheet does not always own that question. When the filing
         * carries the FSIC clearance the BFP sheet asks it and the zoning sheet
         * carries the answer read-only — so the name is on ANOTHER row of
         * `application_office_forms`, and the stored zoning payload holds the
         * empty string it was derived to. Reading it raw would mean a filing
         * that names a representative on the only sheet that asks for one is
         * never told to bring the authorization letter.
         */
        $answers = OfficeFormAnswers::derive(
            $application,
            'ZONING',
            self::sheet($application)?->form_data ?? [],
        );
        $representative = trim((string) ($answers['authorized_representative'] ?? ''));

        $uploaded = self::uploads($application);
        $carried = self::carried($application);
        $submitted = self::sheetSubmitted($application);

        $rows = [];
        foreach (self::ROWS as $row) {
            $applies = match ($row['when']) {
                'owned' => ! $tenureKnown || ! $rented,
                'rented' => ! $tenureKnown || $rented,
                'representative' => $representative !== '',
                default => true,
            };
            if (! $applies) {
                continue;
            }

            $code = self::CODE_PREFIX.$row['key'];
            $source = match (true) {
                $row['carried_from'] === 'sheet' => 'sheet',
                $row['carried_from'] !== null => 'carried',
                default => 'upload',
            };

            $document = match ($source) {
                'upload' => $uploaded[$code] ?? null,
                'carried' => $carried[$row['carried_from']] ?? null,
                default => null,
            };

            $rows[] = [
                'key' => $row['key'],
                'code' => $source === 'upload' ? $code : null,
                'label' => $row['label'],
                'note' => $row['note'],
                'source' => $source,
                'satisfied' => $source === 'sheet' ? $submitted : $document !== null,
                'document' => $document,
            ];
        }

        return $rows;
    }

    /** Is `$code` a slot this checklist accepts a file into? */
    public static function accepts(string $code): bool
    {
        foreach (self::ROWS as $row) {
            if ($row['carried_from'] === null && self::CODE_PREFIX.$row['key'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The document type behind one slot, created on demand.
     *
     * `firstOrCreate` rather than a seeded row for the same reason
     * `HeldPermits::documentType` does it: the checklist is data in this class,
     * and a slot added here should not need a migration before an applicant can
     * upload into it. The name and help text come from the row, so the officer
     * reading the filing's attachment list sees CPDD's own wording.
     */
    public static function documentType(string $code): DocumentType
    {
        $row = collect(self::ROWS)->firstWhere('key', substr($code, strlen(self::CODE_PREFIX)));

        return DocumentType::firstOrCreate(
            ['code' => $code],
            [
                'name' => $row['label'] ?? $code,
                'help_text' => $row['note'] ?? null,
            ],
        );
    }

    /**
     * The files already uploaded into this checklist's slots, keyed by code.
     *
     * Keyed on the DOCUMENT TYPE, deliberately, and not on `permit_type_id` —
     * which is what `HeldPermits` keys on for the certificate an applicant
     * already holds. Two writers of one column is how the held copy and the
     * checklist would delete each other's files, so they do not share one: a
     * checklist upload leaves `permit_type_id` null and is found by its type.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function uploads(Application $application): array
    {
        return ApplicationDocument::with('documentType:id,code')
            ->where('application_id', $application->id)
            ->whereHas('documentType', fn ($q) => $q->where('code', 'like', self::CODE_PREFIX.'%'))
            ->latest('id')
            ->get()
            ->groupBy(fn (ApplicationDocument $d) => (string) $d->documentType?->code)
            ->map(fn ($group) => self::describe($group->first()))
            ->all();
    }

    /**
     * The BPLO attachments that already answer a checklist row.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function carried(Application $application): array
    {
        return ApplicationDocument::with('documentType:id,code')
            ->where('application_id', $application->id)
            ->whereNull('permit_type_id')
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', ['DTI_SEC_CDA', 'LEASE_TITLE']))
            ->latest('id')
            ->get()
            ->groupBy(fn (ApplicationDocument $d) => (string) $d->documentType?->code)
            ->map(fn ($group) => self::describe($group->first()))
            ->all();
    }

    /** @return array<string, mixed> */
    private static function describe(ApplicationDocument $document): array
    {
        return [
            'id' => $document->id,
            'filename' => $document->original_filename,
            'size_bytes' => $document->size_bytes,
            'uploaded_at' => $document->created_at?->toIso8601String(),
        ];
    }

    private static function sheet(Application $application): ?ApplicationOfficeForm
    {
        return ApplicationOfficeForm::where('application_id', $application->id)
            ->whereHas('permitType', fn ($q) => $q->where('code', 'ZONING'))
            ->first();
    }

    /** Has the applicant handed this sheet in, rather than merely saved it? */
    private static function sheetSubmitted(Application $application): bool
    {
        return $application->permitTypes()
            ->where('code', 'ZONING')
            ->wherePivotNotNull('submitted_at')
            ->exists();
    }
}
