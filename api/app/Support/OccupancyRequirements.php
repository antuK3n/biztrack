<?php

namespace App\Support;

use App\Models\Application;
use App\Models\DocumentType;

/**
 * The City Engineering Department's "CHECKLIST OF REQUIREMENTS FOR THE
 * APPLICATION OF OCCUPANCY PERMIT".
 *
 * ── The paper asks for two copies of nearly everything ───────────────────────
 *
 * "Two (2) copies of duly accomplished…", "Two (2) Photocopies of…". Counts are
 * not carried here and that is deliberate: the count is about paper handed over
 * a counter, and a file uploaded once is uploaded once. The office prints what
 * it needs. Recording "2" against a single PDF would be recording a fact about
 * a process this system replaces.
 *
 * ── Three rows the checklist names are not uploads ───────────────────────────
 *
 * The application form for the Certificate of Occupancy and the FSIC
 * application form are both sheets in BizTrack — this one and BFP's — so they
 * are ticked by being submitted rather than attached. The zoning clearance is
 * an attachment the business permit application already collected, so it is
 * carried rather than asked for twice.
 *
 * ── What stays a document, and why ───────────────────────────────────────────
 *
 * The Certificate of Completion, the as-built plans, the relocation survey and
 * the professionals' PRC cards are all signed and SEALED by licensed
 * professionals, and several are notarised. The client settled this on
 * 27 September 2026: they are uploads, not fields. A sealed page typed back in
 * by a business owner is an unsigned copy of the document the Building
 * Official needs, and the original is required either way.
 */
final class OccupancyRequirements
{
    public const CODE_PREFIX = 'OCC_REQ_';

    /**
     * `when` is the branch: 'always', 'changed_plans', 'changed_professional'
     * or 'representative'.
     *
     * @var list<array{key: string, label: string, when: string, note: string, carried_from: ?string, blocking?: bool}>
     */
    public const ROWS = [
        [
            'key' => 'FORM',
            'label' => 'Application Form for Certificate of Occupancy',
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
            'key' => 'COMPLETION',
            'label' => 'Certificate of Completion, notarised — all pages',
            'when' => 'always',
            /*
             * Named as the whole instrument since 4 October 2026. Form B-10
             * carries the cost summary and the Design Professionals and
             * Supervisors of Specialty Works pages — some eighty PRC, PTR and
             * TIN entries signed and sealed by each professional. The client
             * chose to take those as this attachment rather than have the owner
             * type them: *"Attach them; the owner types the rest."*
             */
            'note' => 'Form B-10, signed and sealed by your architect or civil engineer, including the Design Professionals and Supervisors of Specialty Works pages. The blank form comes from the Office of the Local Building Official.',
            'carried_from' => null,
            'blocking' => true,
        ],
        [
            'key' => 'BUILDING_PERMIT',
            'label' => 'Building Permit and issued ancillary permits',
            'when' => 'always',
            'note' => 'Electrical, mechanical, electronics, sanitary and plumbing, as issued.',
            'carried_from' => null,
        ],
        [
            'key' => 'RECEIPTS',
            'label' => 'Official receipts for the building and ancillary permits',
            'when' => 'always',
            'note' => 'Including the environmental clearance, contractor’s tax and BFP receipts.',
            'carried_from' => null,
        ],
        [
            'key' => 'PLANS',
            'label' => 'Approved building plans',
            'when' => 'always',
            'note' => 'As approved by the Building Official.',
            'carried_from' => null,
        ],
        [
            'key' => 'AS_BUILT',
            'label' => 'As-built plans (if the building differs from the approved plan)',
            'when' => 'always',
            'note' => 'Three sets, where anything was changed during construction.',
            'carried_from' => null,
        ],
        /*
         * ── Two rows from the OTHER paper OBO hands out ──────────────────
         *
         * This list is the City Engineering "CHECKLIST OF REQUIREMENTS FOR
         * THE APPLICATION OF OCCUPANCY PERMIT", and that checklist does not
         * mention either of these. OBO's own UNIFIED APPLICATION FORM FOR
         * CERTIFICATE OF OCCUPANCY AND FIRE SAFETY INSPECTION CERTIFICATE
         * does: its "Requirements submitted" block prints seven boxes, and
         * the Construction Logbook and the site photographs are two of them
         * that appear nowhere on the checklist.
         *
         * Verified against both papers on 1 October 2026. One office, two
         * sheets, two different lists — so collecting only the checklist's
         * left OBO chasing these by hand after the filing arrived.
         *
         * NOT blocking, unlike the Certificate of Completion and the
         * owner's ID. The Unified form prints them as boxes the applicant
         * TICKS when submitted rather than as conditions of filing, and the
         * checklist — the sheet actually titled "requirements for the
         * application" — does not ask for them at all. Refusing a filing
         * over a document only one of the two papers names would be our
         * rule rather than the city's; asking for it is not.
         */
        [
            'key' => 'CONSTRUCTION_LOGBOOK',
            'label' => 'Construction Logbook',
            'when' => 'always',
            'note' => 'Signed and sealed by the architect or civil engineer who undertook full-time inspection and supervision.',
            'carried_from' => null,
        ],
        [
            'key' => 'SITE_PHOTOS',
            'label' => 'Photographs of the site showing substantial completion',
            'when' => 'always',
            'note' => 'Photographs of the project as it stands, showing the work substantially complete.',
            'carried_from' => null,
        ],
        [
            'key' => 'RELOCATION_SURVEY',
            'label' => 'Relocation survey by a licensed Geodetic Engineer',
            'when' => 'always',
            'note' => 'The survey actually conducted on the site.',
            'carried_from' => null,
        ],
        [
            'key' => 'PROFESSIONAL_IDS',
            'label' => 'PRC Identification Card and Professional Tax Receipt of each professional',
            'when' => 'always',
            'note' => 'Signed with three specimen signatures and sealed by each professional.',
            'carried_from' => null,
        ],
        [
            'key' => 'CHANGE_OF_PROFESSIONAL',
            'label' => 'Affidavit of Change of Professional',
            'when' => 'always',
            'note' => 'Only where the professional in charge of the project changed.',
            'carried_from' => null,
        ],
        [
            'key' => 'ZONING_CLEARANCE',
            'label' => 'Local / Zoning Clearance',
            'when' => 'always',
            'note' => 'Carried from your business permit documents.',
            'carried_from' => 'LOCATION_SKETCH',
        ],
        [
            'key' => 'FIRE_SAFETY_CHECKLIST',
            'label' => 'Owner’s copy of the Fire Safety Checklist and the FSEC',
            'when' => 'always',
            'note' => 'The Fire Safety Evaluation Clearance and its checklist, from BFP.',
            'carried_from' => null,
        ],
        [
            'key' => 'OWNER_ID',
            'label' => 'Government-issued ID of the owner',
            'when' => 'always',
            'note' => 'With three original specimen signatures.',
            'carried_from' => null,
            'blocking' => true,
        ],
        [
            'key' => 'SPA',
            'label' => 'Authorization letter or SPA, with the representative’s valid ID',
            'when' => 'representative',
            'note' => 'Asked because your FSIC form names an authorised representative. Three specimen signatures.',
            'carried_from' => 'SPA_AUTHORIZATION',
        ],
    ];

    /** @return list<array<string, mixed>> */
    public static function forApplication(Application $application): array
    {
        /*
         * The representative is answered on BFP's sheet — see
         * `OfficeFormAnswers` — so it is read through `derive` rather than off
         * this sheet, which never asks the question.
         */
        $answers = OfficeFormAnswers::derive(
            $application,
            'FSIC',
            ChecklistSupport::sheet($application, 'FSIC')?->form_data ?? [],
        );
        $representative = trim((string) ($answers['authorized_representative'] ?? ''));

        return ChecklistSupport::build(
            $application,
            'OCCUPANCY',
            self::CODE_PREFIX,
            self::ROWS,
            fn (array $row) => $row['when'] === 'representative'
                ? $representative !== ''
                : true,
        );
    }

    public static function accepts(string $code): bool
    {
        return ChecklistSupport::accepts(self::ROWS, self::CODE_PREFIX, $code);
    }

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
            if (($row['carried_from'] ?? null) !== 'sheet') {
                $out[] = self::CODE_PREFIX.$row['key'];
            }
        }

        return $out;
    }

    public static function documentType(string $code): DocumentType
    {
        return ChecklistSupport::documentType(self::ROWS, self::CODE_PREFIX, $code);
    }
}
