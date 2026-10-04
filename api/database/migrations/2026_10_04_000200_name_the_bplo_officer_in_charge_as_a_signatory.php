<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The BPLO's Officer-in-Charge, beside the City Mayor on the Mayor's Permit.
 *
 * The City's own form, photographed at the counter [client, 4 October 2026],
 * signs in two places: HON. JEANNIE N. SANDOVAL over "CITY MAYOR" on the left,
 * and the Licensing Officer over "OIC-BPLO" on the right. The Mayor landed as
 * a signatory row on 4 October; this is the other half of that pair.
 *
 * ── Why this is not the officer who handled the filing ─────────────────────
 *
 * The certificate already names that person — `PermitFace` freezes the
 * assignment's officer onto the permit at issue, and the sheet prints them as
 * "Officer-in-Charge". That is a fact about one filing: who reviewed it.
 *
 * "OIC-BPLO" is a different thing. It is the office's own head, the same name
 * on every permit the City issues this year, and it is pre-printed on the pad
 * the counter writes on. A per-filing officer cannot stand in for it, because
 * a Mayor's Permit released at payment may have no reviewer yet while the
 * signature block on the paper is never blank.
 *
 * A row, not a constant, for the reason the Mayor is one: the post turns over,
 * and `biztrack:signatory` changes it on a running server.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bplo = DB::table('departments')->where('code', 'BPLO')->value('id');
        if ($bplo === null) {
            return;
        }

        $present = DB::table('office_signatories')
            ->where('department_id', $bplo)
            ->where('role', 'OIC-BPLO')
            ->exists();

        if (! $present) {
            DB::table('office_signatories')->insert([
                'department_id' => $bplo,
                'role' => 'OIC-BPLO',
                'name' => 'Alexander T. Rosete, Ph.D.',
                // After the City Mayor, which sorts at 0 — the paper reads
                // left to right, Mayor first.
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Left in place, as the Mayor's row is: the name may have been
        // corrected since, and dropping it leaves a ruled line where the
        // Licensing Officer signs.
    }
};
