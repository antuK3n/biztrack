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
 * FIVE of the paper's items are already on the filing by the time the applicant
 * reaches this sheet, and they are shown as satisfied rather than asked again:
 *
 *  - DTI / SEC Articles is `DTI_SEC_CDA`.
 *  - The title (owned) is `LAND_TITLE`; the lease (rented) is `LEASE_CONTRACT`.
 *  - The sketch is `LOCATION_SKETCH` — BPLO's documentary requirements item 5,
 *    "Sketch and photos of location of business".
 *  - The authorisation is `SPA_AUTHORIZATION` — BPLO's item 6.
 *  - "Completely filled-up the application form" is THIS sheet. It is ticked
 *    when the sheet has been submitted, and nothing is uploaded for it.
 *
 * ── The title and lease pointers were BROKEN for one release ──────────────
 *
 * Both said `LEASE_TITLE`, which was correct while BPLO demanded a single
 * "Lease Contract or Land Title" of every filing. On 16 September 2026 that
 * requirement was split in two and gated on the rent answer, and LEASE_TITLE
 * was detached from the business permit — leaving these two rows pointing at a
 * document nobody is asked for any more. They rendered as "attached with your
 * business permit documents" and could never be satisfied, with no upload slot
 * to fix it, because a carried row has none by design.
 *
 * Any future change to BPLO's requirement list has to come back here. A carried
 * row is a claim about another screen, and nothing fails loudly when that claim
 * stops being true.
 *
 * ASSUMPTION, recorded because it is the one judgement call in the mapping:
 * that CPDD's "Transfer Certificate of Title" and BPLO's "Tax Declaration /
 * Transfer Certificate of Title (TCT)" are the same piece of paper, and its
 * "Contract of Lease" and BPLO's likewise. If CPDD wants its own copy regardless — offices do sometimes
 * keep separate files — these become upload slots like the rest, a one-line
 * change to `carried_from` below. See questions-for-malabon C9 item 4, which
 * asked this and has not been answered.
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
            /*
             * Its own state, in words. This row has no file and no
             * upload box — the tick beside it WAS its status, and the
             * checklist stopped drawing ticks on 30 September 2026.
             */
            'note' => 'This sheet. It counts as complete once you submit it.',
            'carried_from' => 'sheet',
        ],
        [
            'key' => 'TCT',
            'label' => 'Transfer Certificate of Title (TCT)',
            'when' => 'owned',
            'note' => 'The land title for the property, which you attached with your business permit documents.',
            'carried_from' => 'LAND_TITLE',
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
            'carried_from' => 'LEASE_CONTRACT',
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
            'note' => 'The sketch and photos you attached with your business permit documents.',
            'carried_from' => 'LOCATION_SKETCH',
        ],
        [
            'key' => 'DECLARATION',
            'label' => 'Applicant Declaration, notarised',
            'when' => 'always',
            'note' => 'Download the template below, sign it before a notary, then upload the scan. The paper says it MUST BE NOTARIZED PRIOR TO SUBMISSION OF APPLICATION.',
            'carried_from' => null,
            /*
             * ── The first row on this checklist that was a gate ──────────────
             *
             * Redundant since 30 September 2026, when every row became one —
             * see the `blocking` default in the builder below. Kept explicit
             * because this row would still be a gate if the default went back
             * the other way, and because the paper says so itself.
             *
             * The panel's original rule was that nothing here blocks the
             * submit: CPDD's paper is a counter checklist a clerk ticks on
             * receipt, and a missing lease was a conversation with the office
             * rather than a reason to refuse the form.
             *
             * This one said otherwise in its own capitals — MUST BE NOTARIZED
             * PRIOR TO SUBMISSION OF APPLICATION — and the client read the
             * consequence off the screen: *"I wonder how I was able to submit
             * the Locational Clearance without submitting the Applicant
             * Declaration."* (17 September 2026.) Nothing downstream can
             * repair it either. A zoning application whose declaration is not
             * sworn is not an application CPDD can act on, so submitting
             * without it buys the applicant a return trip and a second wait.
             *
             * Notarisation itself is still an open question with the LGU
             * (questions-for-malabon C9 item 2) and this does not settle it —
             * what it settles is that the scan has to BE here, however the
             * signing happens. If BPLO comes back and says the counter accepts
             * an unsworn copy, this flag is the one line to turn off.
             */
            'blocking' => true,
        ],
        [
            'key' => 'AUTHORIZATION',
            'label' => 'Authorization Letter',
            'when' => 'representative',
            'note' => 'The authorisation you attached with your business permit documents, for the person filing on your behalf.',
            'carried_from' => 'SPA_AUTHORIZATION',
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
        /* Every file per slot — see ChecklistSupport::uploadsAll. */
        $everyUpload = ChecklistSupport::uploadsAll($application, self::CODE_PREFIX);
        $carried = self::carried($application);
        $carriedAll = ChecklistSupport::carriedAll($application);
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
                /*
                 * The slot this row uploads into. Null only on a `sheet`
                 * row, which is the form itself.
                 *
                 * A carried row had none until 30 September 2026 — the
                 * business permit answered it, so asking again was asking
                 * twice. What that missed is the applicant with a
                 * two-page lease and one page attached, and, once every
                 * documentary row began blocking the submit, the
                 * applicant with an empty carried row and no way on earth
                 * to fill it: `DocumentController::store` allows Draft and
                 * Returned, and this stage is reached only once the filing
                 * is paid.
                 */
                'code' => $source === 'sheet' ? null : $code,
                'label' => $row['label'],
                'note' => $row['note'],
                'source' => $source,
                /*
                 * The business-permit attachment that answers this row,
                 * when one does. `code` above is null on a carried row —
                 * there is no slot to upload into — so without this the
                 * payload said which document is missing only in the
                 * row's prose, which is not something a caller can act
                 * on. Null on the rows where the question does not
                 * arise: an upload row IS its own source, and a sheet
                 * row is this form.
                 */
                'carried_from' => $source === 'carried' ? $row['carried_from'] : null,
                /*
                 * A carried row is answered by the business permit's copy OR
                 * by one added here — the second half is what keeps a
                 * blocking carried row from being a dead end.
                 */
                'satisfied' => match ($source) {
                    'sheet' => $submitted,
                    'carried' => $document !== null || ($everyUpload[$code] ?? []) !== [],
                    default => $document !== null,
                },
                /*
                 * Whether an unsatisfied row stops the sheet being handed
                 * in. Everything the office asks for does.
                 *
                 * This defaulted to FALSE until 30 September 2026, with
                 * the declaration the one exception, on the reading that
                 * the paper is a counter checklist a clerk ticks on
                 * receipt rather than a gate. The client reversed it from
                 * the Locational Clearance screen — *"Are the documentary
                 * fields here not required? Make sure they are required"*
                 * — and the reversal is the LGU's own logic: the office
                 * cannot act on a filing missing the documents its
                 * decision rests on, so accepting one only buys the
                 * applicant a return trip and a second wait.
                 *
                 * Not a flat `true`. A `sheet` row IS this form, and its
                 * `satisfied` above is $submitted — false at the moment of
                 * submitting. Blocking on it would mean the sheet could
                 * never be handed in, because what it waits for is the act
                 * it is refusing. A row may still opt out by saying so.
                 *
                 * Emitted per row rather than kept as a list of keys in
                 * the browser, for the reason this layer exists: the
                 * applicant's sheet, the server's refusal and the
                 * officer's review screen read one rule, not three.
                 */
                'blocking' => $row['blocking'] ?? (
                    $source !== 'sheet' && ! ChecklistSupport::statedAsConditional($row['label'])
                ),
                'document' => $document,
                /*
                 * Every file answering this row. A carried row lists what
                 * the business permit holds FIRST — that is the copy the
                 * office already has — then anything added here.
                 */
                'documents' => match ($source) {
                    'upload' => $everyUpload[$code] ?? [],
                    'carried' => array_merge(
                        $carriedAll[$row['carried_from']] ?? [],
                        $everyUpload[$code] ?? [],
                    ),
                    default => [],
                },
                /*
                 * Which of those belong to the business permit. Remove on
                 * this sheet means "I attached the wrong page to this
                 * checklist", never "take it off my business permit", so
                 * the screen offers it on nothing in this list.
                 */
                'carried_document_ids' => $source === 'carried'
                    ? array_column($carriedAll[$row['carried_from']] ?? [], 'id')
                    : [],
            ];
        }

        return $rows;
    }

    /** Is `$code` a slot this checklist accepts a file into? */
    public static function accepts(string $code): bool
    {
        foreach (self::ROWS as $row) {
            // A `sheet` row is the form itself and takes nothing; every
            // other row has a slot, including a carried one.
            if ($row['carried_from'] !== 'sheet' && self::CODE_PREFIX.$row['key'] === $code) {
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
    /**
     * Every slot on this checklist, whether or not a file is in one.
     *
     * A `sheet` row is the form itself and is not a slot. Used to declare
     * the document types up front — see ReferenceSeeder — so the set a
     * register holds does not depend on which offices have happened to
     * receive an upload.
     *
     * @return list<string>
     */
    public static function slotCodes(): array
    {
        $out = [];
        foreach (self::ROWS as $row) {
            if ($row['carried_from'] !== 'sheet') {
                $out[] = self::CODE_PREFIX.$row['key'];
            }
        }

        return $out;
    }

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
        return ChecklistSupport::uploads($application, self::CODE_PREFIX);
    }

    /**
     * The BPLO attachments that already answer a checklist row.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function carried(Application $application): array
    {
        return ChecklistSupport::carried($application);
    }

    /** @return array<string, mixed> */
    private static function describe(ApplicationDocument $document): array
    {
        return ChecklistSupport::describe($document);
    }

    private static function sheet(Application $application): ?ApplicationOfficeForm
    {
        return ChecklistSupport::sheet($application, 'ZONING');
    }

    /** Has the applicant handed this sheet in, rather than merely saved it? */
    private static function sheetSubmitted(Application $application): bool
    {
        return ChecklistSupport::sheetSubmitted($application, 'ZONING');
    }
}
