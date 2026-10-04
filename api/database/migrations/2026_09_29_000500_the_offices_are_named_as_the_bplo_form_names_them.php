<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * ── One name per office, and it is the LGU's ────────────────────────────────
 *
 * Client, 29 September 2026, with MCG-BPLO-FO-001's own table beside the
 * screen: *"The namings of offices and permits are inconsistent throughout the
 * system. Please align them here."*
 *
 * The form is the authority — it is what the applicant is holding and what the
 * counter works from — and five names disagreed with it:
 *
 *   permit_types.SANITARY   "Sanitary Permit / Health Certificate"
 *                        -> "Sanitary Permit"
 *                           The form prints both halves; the client's
 *                           instruction of the same day was the shorter one.
 *   permit_types.ZONING     "Zoning / Locational Clearance"
 *                        -> "Zoning Clearance"
 *   departments.CPDO        "City Planning and Development Office (Zoning)"
 *                        -> "Planning/Zoning Office"
 *   departments.OBO         "Office of the Building Official"
 *                        -> "Office of the Local Building Official"
 *   departments.CENRO       "City Environment and Natural Resources Office"
 *                        -> "City Environmental and Natural Resources Office"
 *
 * The last is one letter and is the kind of difference that survives for
 * years: "Environment" against the form's "Environmental".
 *
 * ── Why a migration and not just the seeder ─────────────────────────────────
 *
 * `ReferenceSeeder` is updated too, so a fresh install is right. But the
 * seeder does not run against a database that already holds filings, and
 * these names are read by every screen from the rows — not from a constant —
 * so without this the running system keeps the old wording for ever.
 *
 * Matched on CODE, never on the old name. The code is the identity; the name
 * is the label, which is the whole reason it could drift.
 *
 * Market Clearance and the Office of the City Market Administrator are on the
 * form and deliberately absent here: that permit was removed from the system,
 * and the client confirmed it stays out.
 */
return new class extends Migration
{
    /** code => the name MCG-BPLO-FO-001 prints. */
    private const DEPARTMENTS = [
        'CPDO' => 'Planning/Zoning Office',
        'OBO' => 'Office of the Local Building Official',
        'CENRO' => 'City Environmental and Natural Resources Office',
    ];

    private const PERMIT_TYPES = [
        'SANITARY' => 'Sanitary Permit',
        'ZONING' => 'Zoning Clearance',
    ];

    /** What each held before, so `down()` is a real reversal. */
    private const WAS_DEPARTMENTS = [
        'CPDO' => 'City Planning and Development Office (Zoning)',
        'OBO' => 'Office of the Building Official',
        'CENRO' => 'City Environment and Natural Resources Office',
    ];

    private const WAS_PERMIT_TYPES = [
        'SANITARY' => 'Sanitary Permit / Health Certificate',
        'ZONING' => 'Zoning / Locational Clearance',
    ];

    public function up(): void
    {
        $this->rename(self::DEPARTMENTS, self::PERMIT_TYPES);
    }

    public function down(): void
    {
        $this->rename(self::WAS_DEPARTMENTS, self::WAS_PERMIT_TYPES);
    }

    private function rename(array $departments, array $permitTypes): void
    {
        foreach ($departments as $code => $name) {
            DB::table('departments')->where('code', $code)->update(['name' => $name]);
        }

        foreach ($permitTypes as $code => $name) {
            DB::table('permit_types')->where('code', $code)->update(['name' => $name]);
        }
    }
};
