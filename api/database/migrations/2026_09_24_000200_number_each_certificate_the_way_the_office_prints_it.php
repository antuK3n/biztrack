<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A permit number that says which certificate it is.
 *
 * ── What was wrong ────────────────────────────────────────────────────────
 *
 * Every certificate was numbered `MC?-YYYY-NNNNNN` — MCB, MCS, MCF, MCZ, MCE,
 * MCO. "MC" for Malabon City and one letter for the office, which means the
 * letter that distinguishes a Fire Safety Inspection Certificate from a
 * Sanitary Permit is the third character of a six-character prefix.
 *
 * Client, 24 September 2026: *"gawin namang ibang permit format kada offices
 * make it tugma sa certificate for example sa fire FSIC-2026-00000X, sa
 * sanitary HC-2026-000004 and so on sa iba pang offices."*
 *
 * So the prefix is the certificate's own acronym, the one already printed on
 * the paper the office issues:
 *
 *   MCF -> FSIC   Fire Safety Inspection Certificate  (BFP, client's example)
 *   MCS -> HC     Health Certificate                  (CHO, client's example)
 *   MCE -> CEC    City Environmental Certificate      (CENRO)
 *   MCZ -> LC     Locational Clearance                (CPDD)
 *   MCO -> OP     Occupancy Permit                    (OBO)
 *   MCB -> MP     Mayor's Permit                      (BPLO)
 *
 * ── Why the Mayor's Permit is MP and not BP ───────────────────────────────
 *
 * `BP-YYYY-NNNN` is already taken, by the business account number on
 * `businesses.ban`. Two different things under one prefix is precisely the
 * mistake the 30 August migration was written to undo, when `BAN-` named both
 * an account number and, to the owner reading it, a sanction. `BP-2026-0006`
 * (a business) beside `BP-2026-000001` (a permit) differ only in the width of
 * the suffix, which is not a distinction a counter can be asked to make.
 *
 * ── What this does NOT do ─────────────────────────────────────────────────
 *
 * It does not renumber a certificate that has already been issued. A permit
 * number is printed on a document somebody is holding, cited in the audit
 * trail and embedded in the QR code that verifies it; rewriting it here would
 * make every copy in circulation fail verification. `Numbering::permitNumber`
 * counts within a prefix, so the new series starts at 000001 and the old
 * numbers keep working exactly as they do now.
 *
 * The SAMPLE rows seeded on 24 September are renumbered separately and
 * deliberately, in their own script rather than here: they are demonstration
 * data created the same day, held by nobody, and a migration that renumbered
 * real permits under a `LIKE 'SAMPLE%'` condition would be one careless WHERE
 * from doing it to the register.
 */
return new class extends Migration
{
    /** Old prefix => new prefix. Keyed by the permit type CODE, which is stable. */
    private const PREFIXES = [
        'BUSINESS' => ['MCB', 'MP'],
        'SANITARY' => ['MCS', 'HC'],
        'FSIC' => ['MCF', 'FSIC'],
        'OCCUPANCY' => ['MCO', 'OP'],
        'CEC' => ['MCE', 'CEC'],
        'ZONING' => ['MCZ', 'LC'],
    ];

    public function up(): void
    {
        foreach (self::PREFIXES as $code => [, $new]) {
            DB::table('permit_types')
                ->where('code', $code)
                ->update(['permit_number_prefix' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::PREFIXES as $code => [$old]) {
            DB::table('permit_types')
                ->where('code', $code)
                ->update(['permit_number_prefix' => $old]);
        }
    }
};
