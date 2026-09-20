<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\PermitType;

/**
 * The answers an office sheet carries that nobody types.
 *
 * ── Why this is a class and not a method on the controller ────────────────
 *
 * It WAS a private method on `OfficeFormController`, and that is exactly the
 * shape this codebase has been bitten by four times: a rule that lives on one
 * of its two consumers is a rule the other consumer cannot obey. The office
 * forms reach a reader through two doors —
 * `GET /applications/{id}/office-forms`, which the controller serves, and
 * `GET /assignments/{id}` → `ApplicationResource`, which the officer's review
 * sheet actually reads — and only the first could call a private method.
 *
 * The cost, reported by the client on 9 September 2026: a CENRO session opened
 * a filing it had been routed and saw the BPLO form and nothing of its own.
 * `ApplicationResource` mapped only the SAVED `officeForms` rows, so a sheet
 * the applicant had not opened did not exist as far as the review screen was
 * concerned — while `/office-forms` had been synthesising it all along, derived
 * answers and all. Same filing, two endpoints, two answers.
 *
 * So the derivation lives here, both doors call it, and neither can drift.
 */
final class OfficeFormAnswers
{
    /**
     * The permit types that HAVE an applicant-facing sheet.
     *
     * The same list `OfficeFormController` used to keep privately, and it is
     * `PermitType::OFFICE_FORM_CODES` at source so a permit type that gains or
     * loses a questionnaire cannot be a sheet here and not there.
     */
    public const FORM_PERMIT_CODES = PermitType::OFFICE_FORM_CODES;

    /**
     * Overlay the answers the system already holds on top of a payload. These
     * always win: the paper form still carries them, but nobody types them.
     */
    public static function derive(Application $application, ?string $permitTypeCode, array $formData): array
    {
        if ($permitTypeCode === null || ! in_array($permitTypeCode, self::FORM_PERMIT_CODES, true)) {
            return $formData;
        }

        // Renewals and amendments both act on a business that already operates;
        // only a genuinely new application is "new" to the inspecting office.
        $existingBusiness = $application->application_type !== ApplicationType::New;

        // The filing date: submitted_at once the application is filed, today
        // while it is still being filled in. Never typed by anyone.
        $derived = [
            'application_date' => ($application->submitted_at ?? now())->toDateString(),
        ];

        if ($permitTypeCode === 'ZONING') {
            // VII. Nature of Application — New Business or Renewal.
            $derived['application_type'] = $existingBusiness
                ? 'Renewal of Locational Clearance'
                : 'New Locational Clearance';

            /*
             * VIII.A. Floor Area to be/being Utilized. The zoning processing
             * fee is charged per square metre of total floor area, so CPDD
             * cannot assess the clearance without this number — and the
             * applicant already gave it on the Business & Tax Profile, where it
             * is required of every filing carrying the business permit itself.
             * Asking again on this sheet would be asking the same question
             * twice and inviting two answers.
             *
             * Left absent rather than zeroed when it is genuinely missing: a
             * blank box the applicant can be sent back to fill is honest, and
             * "0 sq. m." on a locational clearance is not.
             */
            $floorArea = $application->fee_profile['floor_area_sqm'] ?? null;
            if (is_numeric($floorArea)) {
                $derived['total_floor_area_sqm'] = (string) (0 + $floorArea);
            }

            /*
             * VIII.B. No. of Storey of Building — a SEED now, not a carry.
             *
             * The BPLO wizard stopped asking for the storey count on 16
             * September 2026: measured against a filing holding all six
             * clearances it moved the total by zero pesos, and the only two
             * rules that read it need a fine permit category still open with
             * BPLO. MCG-CPDD-FO-003 is now the one paper that asks, so the
             * sheet takes the answer itself and the field there is editable.
             *
             * This is kept so a filing saved while the wizard still asked
             * arrives with the box already filled rather than blank. Cast to
             * an int because a building has whole storeys and "2.0" on a
             * planning form invites a question nobody meant to ask.
             */
            $storeys = $application->fee_profile['storeys'] ?? null;
            if (is_numeric($storeys)) {
                $derived['building_storeys'] = (string) (int) $storeys;
            }

            /*
             * ── VIII.C and VIII.D are ASKED now, not derived ─────────────────
             *
             * They were carried from `businesses.lessor_name` and
             * `lessor_address`, on the reasoning that Location & Zoning had
             * already collected both and the applicant should not retype them.
             * That reasoning died with the fields: MCG-BPLO-FO-001 asks whether
             * rent is paid and nothing about the lessor, so the client removed
             * the four lessor boxes from the wizard on 16 September 2026.
             *
             * Deriving them now would print two permanently empty boxes on
             * CPDD's sheet — the office asks "NAME OF LESSOR (IF LESSEE)" and
             * "ADDRESS OF LESSOR (IF LESSEE)" and would get nothing, because no
             * screen writes those columns any more. So the SHEET takes the
             * answer, in the form's own two boxes, and it stays in the sheet's
             * `form_data` where the officer's review screen already reads it.
             *
             * `is_rented` still decides whether they are shown: the paper's own
             * "(if lessee)", and that answer is still asked, on section B item
             * 8. What changed is who types the lessor's details, not who knows
             * whether there is one.
             *
             * The columns are left in place and are still writable through the
             * business endpoint — 139 filings point at businesses that hold
             * values, and an office reading an older filing should still find
             * them. Nothing new is written there.
             */
            $derived['site_is_rented'] = ($application->business?->is_rented ?? false) ? 'yes' : 'no';

            /*
             * IX. Authorized Representative. One answer about the applicant,
             * not about an office, and the BFP sheet has always asked it — so
             * when that sheet is part of the filing it keeps the question and
             * this one carries the answer read-only. When it is not, nobody has
             * asked, and the zoning sheet takes the input itself.
             *
             * The marker is what tells the sheet which of those it is; the
             * value is derived even when blank, so clearing the name on the BFP
             * sheet clears it here too. That does mean applying for FSIC after
             * typing a name here replaces it with the (empty) BFP answer. Two
             * visible fields that disagree would be worse than losing an
             * optional name at the moment a second sheet takes the question
             * over.
             */
            if ($application->permitTypes()->where('code', 'FSIC')->exists()) {
                $derived['authorized_representative_source'] = 'FSIC';
                $derived['authorized_representative'] = self::fsicRepresentative($application);
            }
        }
        if ($permitTypeCode === 'SANITARY') {
            $derived['application_type'] = $existingBusiness ? 'Renewal' : 'New';

            /*
             * "No. of Workers Requiring Health Certificates" — the same
             * question the Business & Tax Profile already asks, and the same
             * one the applicant is BILLED on.
             *
             * `sanitary.health_certificate` (Sec. 4D.02) charges ₱50 per
             * employee per year, `basis: employees`, gated on the
             * `employees_need_health_certificates` flag — so the number the
             * City Health Office actually assesses is `fee_profile.employees`,
             * declared on the profile. This sheet then asked for it a second
             * time, in a free-text box nothing reads.
             *
             * That is the capitalization case again, and it is worse here:
             * capitalization's two answers merely drifted, whereas these two
             * appear on the same filing as a number on the CHO's own sheet and
             * a different number on the Tax Order of Payment for the same fee.
             * An officer reading "4 workers" beside a bill for five is looking
             * at a discrepancy the applicant never made.
             *
             * So it is derived, exactly as the zoning sheet's floor area is.
             * Where the flag is not set, no employee needs a certificate and
             * the fee is not charged — "None" is the honest answer, not blank.
             *
             * ASSUMPTION (docs/questions-for-malabon.md §E — the CHO paper form
             * has been requested and not received): the paper's box means every
             * employee who must hold a certificate, which is what the ordinance
             * bills. If CHO confirms it means some narrower subset — office
             * staff excluded from a food establishment's count, say — then it
             * is a genuinely separate question and should be asked again here,
             * AND the fee basis is wrong, because the fee would be billing the
             * wrong headcount today.
             */
            $flags = $application->fee_profile['flags'] ?? [];
            $needsCertificates = is_array($flags)
                && in_array('employees_need_health_certificates', $flags, true);
            $employees = $application->fee_profile['employees'] ?? null;

            $derived['workers_requiring_health_certs'] = match (true) {
                ! $needsCertificates => 'None',
                is_numeric($employees) => (string) (int) $employees,
                // Flagged but no headcount: the profile is half-filled, so say
                // nothing rather than print a zero the office would act on.
                default => '',
            };
        }
        if ($permitTypeCode === 'CEC') {
            $derived['application_type'] = $existingBusiness ? 'Renewal of CEC' : 'Initial Application';

            /*
             * "REQUIRED DENR PERMITS FOR APPLICATION", the right-hand half of
             * MCG-CENRO-FO-001 v2.0, answered for THIS applicant.
             *
             * A list, not a collection point — the client was explicit that the
             * sheet is not where these are handed in, and the paper's own
             * footnote gives the applicant six months from issuance to comply.
             * What the system can usefully do is tell them which ones apply
             * before they leave, which is the one thing the paper cannot.
             *
             * Read off the DECLARED CATEGORIES, the same field the CENRO
             * environmental fee is computed from
             * (`conditions.business_category` on the `env.*` rules), so the
             * money and the paperwork name the same row of the same table.
             *
             * Absent, not blank, when nothing matches. `array_filter` drops the
             * whole block, so the sheet renders its "CENRO will determine this"
             * state rather than a row of empty boxes that read as an answer of
             * "none required". See DenrRequirements::forCategories for why an
             * unmatched category is left unmatched rather than guessed.
             */
            $categories = array_values(array_filter(array_map(
                fn (array $line) => (string) ($line['category'] ?? ''),
                (array) ($application->fee_profile['lines'] ?? []),
            )));

            /*
             * The PSIC line of business as well as the declared category, and
             * the second one is what makes this useful.
             *
             * Reading the category alone left most filings unplaced: it is free
             * text with a datalist of the Revenue Code's business-tax words, and
             * CENRO's table uses its own. The PSIC code is the standard
             * classification the applicant picked from a list of 135 — 47300 is
             * a filling station whatever its owner called their trade.
             */
            // `loadMissing`, not a bare read: this method is reached from index()
            // and from upsert(), neither of which eager-loads the business's
            // lines, and a lazy read would be an N+1 at best and a
            // LazyLoadingViolation the day strict mode is turned on.
            $application->loadMissing('business.lines.psicCode');

            $psicCodes = $application->business?->lines
                ->map(fn ($line) => (string) ($line->psicCode->code ?? ''))
                ->filter()
                ->values()
                ->all() ?? [];

            /*
             * The Official Receipt was derived here for a moment and is not any
             * more. The sheet briefly reported it as on file, until the client
             * pointed out the simpler truth: CENRO can open the receipt itself,
             * so telling the applicant its number solved nothing either. Neither
             * an upload nor a marker — the office already has it.
             */
            $denr = DenrRequirements::resolve($categories, $psicCodes);
            $derived['denr_reason'] = $denr['reason'];
            if ($denr['row'] !== null) {
                $derived['denr_basis'] = $denr['row']['label'];
                $derived['denr_certificate'] = $denr['row']['certificate'];
                $derived['denr_permits'] = $denr['row']['permits'] === []
                    ? 'None'
                    : implode(', ', $denr['row']['permits']);
                $derived['denr_pco'] = $denr['row']['pco'] ? 'Required' : 'Not required';
                if ($denr['row']['remarks'] !== '') {
                    $derived['denr_remarks'] = $denr['row']['remarks'];
                }
            }
        }
        if ($permitTypeCode === 'FSIC') {
            $forOccupancy = $application->permitTypes()->where('code', 'OCCUPANCY')->exists();
            $derived['certificate_applied_for'] = match (true) {
                $forOccupancy => 'FSIC for Certificate of Occupancy',
                $existingBusiness => 'FSIC for Business Permit (Renewal of Business)',
                default => 'FSIC for Business Permit (New Business)',
            };
        }
        // The MARKET branch was here. Market Clearance and the CMO Market Office
        // were removed from the system on 6 September 2026 — see the note in
        // ReferenceSeeder where the department used to be seeded.
        //
        // OCCUPANCY's own "application_type" is Full vs Partial occupancy — a
        // real applicant decision, not the new/renewal the system already knows.

        return $derived + $formData;
    }

    /**
     * The authorised representative as answered on the BFP sheet, or ''.
     *
     * Its own query rather than a preloaded relation because withDerived() runs
     * for one sheet at a time and is reached from both index() and upsert(); a
     * sheet asking for another sheet's answer is the exception, not the rule,
     * and it only happens for ZONING.
     */
    private static function fsicRepresentative(Application $application): string
    {
        $form = ApplicationOfficeForm::where('application_id', $application->id)
            ->whereHas('permitType', fn ($query) => $query->where('code', 'FSIC'))
            ->first();

        return trim((string) ($form?->form_data['authorized_representative'] ?? ''));
    }
}
