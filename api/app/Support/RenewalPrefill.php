<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\Permit;
use App\Models\PermitType;

/**
 * Last year's answers, offered to this year's renewal.
 *
 * ── Why this exists ───────────────────────────────────────────────────────
 *
 * A renewal's office form is the SAME form as a new application's — the client
 * confirmed that on 17 September 2026 — and the office needs the same facts
 * about the premises every year. Nothing carried them forward, so an applicant
 * renewing their Sanitary Permit re-typed the water source, the sanitary
 * classification and the rest annually, while last year's answers sat in
 * `application_office_forms` on the prior filing, reachable through the renewal
 * chain and read by nothing.
 *
 * ── Offered, not applied ──────────────────────────────────────────────────
 *
 * This returns a map the CLIENT seeds empty fields from. It deliberately does
 * not write to `application_office_forms`: the applicant must be able to see
 * which answers came from last year and confirm them, and a value the server
 * had already stored would be indistinguishable from one they had reviewed.
 * The client's decision was "filled in and flagged, per field", and a flag you
 * cannot tell from a real answer is not a flag.
 *
 * ── Three kinds of answer never carry ─────────────────────────────────────
 *
 * DERIVED. `OfficeFormAnswers::derive` computes these from the filing —
 * `application_date`, `application_type`, the FSIC sheet's whole payload — and
 * they must describe THIS filing. They are excluded by asking `derive` itself
 * what it produces from an empty payload, so the list maintains itself: a
 * derived answer added next year is excluded the day it is added, with no
 * constant here to forget. (They would also be harmless-but-confusing if they
 * slipped through — `derive` returns `$derived + $formData`, so a derived key
 * always wins — but writing a value nothing reads is how a reader later spends
 * an hour wondering which one is authoritative.)
 *
 * OFFICER-WRITTEN. `building_permit_date`, `fsec_date`, `date_issued`: dates
 * the OFFICE fills in when it issues. Carrying them forward would print last
 * year's issuance dates on this year's certificate.
 *
 * ATTESTATIONS. A certification tick is an ACT, not a fact. Carrying
 * `certified` forward would sign this year's form with last year's signature,
 * which is the one thing in this file that would be genuinely wrong rather
 * than merely untidy. The client called it out as the exclusion that mattered.
 */
final class RenewalPrefill
{
    /**
     * Answers the OFFICE writes when it issues, never the applicant.
     *
     * Kept in step with `OfficeFormController::OFFICER_KEYS` by being asserted
     * against it in `RenewalPrefillTest` — two lists, one test, rather than a
     * shared constant that would make a controller's private detail public.
     */
    public const OFFICER_KEYS = ['building_permit_date', 'fsec_date', 'date_issued'];

    /**
     * Answers that are an ACT of the applicant rather than a fact about them.
     *
     * `certified` is MCG-CENRO-FO-001's "I certify that the above details are
     * correct". `officeFormMissing` makes it a required answer, so an applicant
     * cannot submit without ticking it — which is exactly why it must arrive
     * unticked.
     */
    public const ATTESTATION_KEYS = ['certified'];

    /**
     * What to offer for one sheet on one renewal, keyed as the form keys them.
     *
     * Empty for anything that is not a renewal, for a permit with no prior, and
     * for a prior filing that never saved that sheet — all of which are the
     * ordinary case on a first renewal and none of which is an error.
     *
     * @return array<string, mixed>
     */
    public static function forSheet(Application $application, string $permitTypeCode): array
    {
        if ($application->application_type !== ApplicationType::Renewal) {
            return [];
        }

        $previous = self::previousSheet($application, $permitTypeCode);
        if ($previous === []) {
            return [];
        }

        $excluded = array_merge(
            array_keys(OfficeFormAnswers::derive($application, $permitTypeCode, [])),
            self::OFFICER_KEYS,
            self::ATTESTATION_KEYS,
        );

        $offered = array_diff_key($previous, array_flip($excluded));

        /*
         * Blank answers are not offered. Last year's empty string is not a fact
         * about the premises, and marking a field "from your 2026 application"
         * when 2026 said nothing would send the applicant looking for an answer
         * that was never given.
         */
        return array_filter(
            $offered,
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );
    }

    /**
     * The same sheet on the filing that issued the permit being renewed.
     *
     * Found through the PERMIT rather than through the business's filing
     * history, and that is the load-bearing choice: a business may have several
     * filings carrying a Sanitary Permit, and the answers worth offering are
     * the ones on the filing that produced the certificate this renewal
     * replaces. `prior_permit_ids` is what the applicant ticked in the entry
     * dialog, so it is their own statement of what they are renewing.
     *
     * @return array<string, mixed>
     */
    private static function previousSheet(Application $application, string $permitTypeCode): array
    {
        $type = PermitType::where('code', $permitTypeCode)->first();
        if ($type === null) {
            return [];
        }

        $prior = $application->priorPermits()
            ->where('permits.permit_type_id', $type->id)
            ->orderByDesc('permits.valid_until')
            ->first();

        if (! $prior instanceof Permit || $prior->application_id === null) {
            return [];
        }

        $sheet = ApplicationOfficeForm::where('application_id', $prior->application_id)
            ->where('permit_type_id', $type->id)
            ->first();

        return is_array($sheet?->form_data) ? $sheet->form_data : [];
    }
}
