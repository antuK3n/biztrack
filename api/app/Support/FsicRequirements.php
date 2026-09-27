<?php

namespace App\Support;

use App\Models\Application;
use App\Models\DocumentType;

/**
 * BFP's "ATTACHED DOCUMENTARY REQUIREMENTS", which is three lists, not one.
 *
 * BFP-QSF-FSED-002 prints a checkbox at the head of each branch and a different
 * set of attachments under it:
 *
 *   FSIC FOR CERTIFICATE OF OCCUPANCY — the building is new or altered and the
 *     Building Official has to endorse it before BFP will look.
 *   FSIC FOR BUSINESS PERMIT · FOR NEW BUSINESS — the building already has its
 *     occupancy certificate and this is a new trade going into it.
 *   FSIC FOR BUSINESS PERMIT · FOR RENEWAL OF BUSINESS — nothing structural has
 *     changed, so BFP wants maintenance evidence rather than construction
 *     evidence.
 *
 * ── Which branch, and why nobody is asked ────────────────────────────────────
 *
 * The same expression that already writes the sheet's "Certificate Applied For"
 * box: OCCUPANCY on the filing means the first, otherwise new-or-renewal by
 * application type. Asking the applicant to tick a box the system has already
 * worked out would invite them to tick a different one, and the checklist and
 * the answer printed above it would then disagree on one sheet.
 *
 * ── "(if necessary)" is BFP's own wording, and it is kept ───────────────────
 *
 * Four rows say it and none of them blocks. A fire insurance policy is not
 * required of every trade, an FSMR is not required of every renewal, and a hot
 * work clearance is required only of premises that do hot work — so the office
 * decides at the counter and the applicant is not stopped from filing by a row
 * that may not apply to them. The one row that blocks is the certification,
 * which is the applicant's own statement and costs nothing to make.
 */
final class FsicRequirements
{
    public const CODE_PREFIX = 'FSIC_REQ_';

    /**
     * The rows, in the paper's own order within each branch.
     *
     * `when` is the branch: 'always', 'occupancy', 'new' or 'renewal'.
     *
     * @var list<array{key: string, label: string, when: string, note: string, carried_from: ?string, blocking?: bool}>
     */
    public const ROWS = [
        /* ── FSIC for Certificate of Occupancy ───────────────────────────── */
        [
            'key' => 'OBO_ENDORSEMENT',
            'label' => 'Endorsement from the Office of the Building Official',
            'when' => 'occupancy',
            'note' => 'OBO endorses the building to BFP once it has checked the plans.',
            'carried_from' => null,
        ],
        [
            'key' => 'COMPLETION',
            'label' => 'Certificate of Completion',
            'when' => 'occupancy',
            'note' => 'Form B-10, notarised and sealed by your architect or civil engineer. The blank form comes from the Office of the Building Official.',
            'carried_from' => null,
        ],
        [
            'key' => 'COO_ASSESSMENT',
            'label' => 'Assessment fee for the Certificate of Occupancy, certified true copy',
            'when' => 'occupancy',
            'note' => 'From OBO, showing what the occupancy certificate was assessed at.',
            'carried_from' => null,
        ],
        [
            'key' => 'AS_BUILT',
            'label' => 'As-built plan (if necessary)',
            'when' => 'occupancy',
            'note' => 'Only where the building differs from the approved plans.',
            'carried_from' => null,
        ],
        [
            'key' => 'FSCCR',
            'label' => 'Fire Safety Compliance and Commissioning Report (if necessary)',
            'when' => 'occupancy',
            'note' => 'One set. BFP asks for it where the building has fire protection systems to commission.',
            'carried_from' => null,
        ],

        /* ── FSIC for Business Permit · new business ─────────────────────── */
        [
            'key' => 'VALID_COO',
            'label' => 'Valid Certificate of Occupancy, certified true copy',
            'when' => 'new',
            'note' => 'The occupancy certificate already issued for the building you are moving into.',
            'carried_from' => null,
        ],
        [
            'key' => 'BPLO_ASSESSMENT_NEW',
            'label' => 'Business permit fee / tax assessment bill from BPLO',
            'when' => 'new',
            'note' => 'Your Tax Order of Payment. It is issued once BPLO approves your form.',
            'carried_from' => null,
        ],
        [
            'key' => 'NO_CHANGES_AFFIDAVIT',
            'label' => 'Affidavit of Undertaking — no substantial changes to the building',
            'when' => 'new',
            'note' => 'Sworn before a notary. A blank copy is below.',
            'carried_from' => null,
        ],
        [
            'key' => 'FIRE_INSURANCE_NEW',
            'label' => 'Fire insurance (if necessary)',
            'when' => 'new',
            'note' => 'A copy of the policy, where your trade carries one.',
            'carried_from' => null,
        ],

        /* ── FSIC for Business Permit · renewal ──────────────────────────── */
        [
            'key' => 'BPLO_ASSESSMENT_RENEWAL',
            'label' => 'Business permit fee / tax assessment bill from BPLO',
            'when' => 'renewal',
            'note' => 'Your Tax Order of Payment for this year.',
            'carried_from' => null,
        ],
        [
            'key' => 'FIRE_INSURANCE_RENEWAL',
            'label' => 'Fire insurance (if necessary)',
            'when' => 'renewal',
            'note' => 'A copy of the policy, where your trade carries one.',
            'carried_from' => null,
        ],
        [
            'key' => 'FSMR',
            'label' => 'Fire Safety Maintenance Report (if necessary)',
            'when' => 'renewal',
            'note' => 'One set, covering the year being renewed.',
            'carried_from' => null,
        ],
        [
            'key' => 'HOT_WORK',
            'label' => 'Fire safety clearance for welding, cutting and other hot work (if required)',
            'when' => 'renewal',
            'note' => 'Only where the premises does hot work.',
            'carried_from' => null,
        ],

        /* ── Every branch ────────────────────────────────────────────────── */
        [
            'key' => 'SPA',
            'label' => 'Authorization letter or SPA, with a copy of the owner’s ID',
            'when' => 'representative',
            'note' => 'Asked because you named an authorised representative above. BFP will not release the certificate to them without it.',
            'carried_from' => 'SPA_AUTHORIZATION',
        ],
        [
            'key' => 'FORM',
            'label' => 'Completely filled-up application form',
            'when' => 'always',
            'note' => 'This sheet. It is ticked when you submit it.',
            'carried_from' => 'sheet',
        ],
    ];

    /**
     * Which of the three branches this filing is on.
     *
     * Read through `OfficeFormAnswers::derive` rather than recomputed here, so
     * the checklist and the "Certificate Applied For" box printed above it
     * cannot disagree — they are the same string.
     */
    public static function branch(Application $application): string
    {
        $applied = OfficeFormAnswers::derive($application, 'FSIC', [])['certificate_applied_for'] ?? '';

        return match (true) {
            str_contains($applied, 'Certificate of Occupancy') => 'occupancy',
            str_contains($applied, 'Renewal') => 'renewal',
            default => 'new',
        };
    }

    /** @return list<array<string, mixed>> */
    public static function forApplication(Application $application): array
    {
        $branch = self::branch($application);

        $answers = OfficeFormAnswers::derive(
            $application,
            'FSIC',
            ChecklistSupport::sheet($application, 'FSIC')?->form_data ?? [],
        );
        $representative = trim((string) ($answers['authorized_representative'] ?? ''));

        return ChecklistSupport::build(
            $application,
            'FSIC',
            self::CODE_PREFIX,
            self::ROWS,
            fn (array $row) => match ($row['when']) {
                'representative' => $representative !== '',
                'always' => true,
                default => $row['when'] === $branch,
            },
        );
    }

    public static function accepts(string $code): bool
    {
        return ChecklistSupport::accepts(self::ROWS, self::CODE_PREFIX, $code);
    }

    public static function documentType(string $code): DocumentType
    {
        return ChecklistSupport::documentType(self::ROWS, self::CODE_PREFIX, $code);
    }
}
