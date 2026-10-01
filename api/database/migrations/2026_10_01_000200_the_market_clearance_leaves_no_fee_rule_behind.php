<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The last row the Market Clearance left behind.
 *
 * ── What is being removed, and why it is dead ───────────────────────────────
 *
 * `market.stall_rental` — "Public market stall rental (officer-set, pending
 * Market Code)". It carries `permit_types: ["MARKET"]`, and the MARKET permit
 * type was removed on 6 September 2026 when the client confirmed with the LGU
 * that neither the Market Clearance nor the Office of the City Market
 * Administrator is needed. A rule keyed to a permit type that no longer exists
 * can never match a filing, so it has priced nothing since that day.
 *
 * It is not in the ordinance either: its own `section` reads "none in
 * A10-2016", because Chapter V covers only city-owned commercial leases and
 * there is no public-market stall schedule to transcribe. It was seeded as a
 * placeholder for a Market Code that has not been written.
 *
 * ── Why a migration and not a seeder change ─────────────────────────────────
 *
 * It is ALREADY gone from the source: `database/data/revenue_code/system.json`
 * holds eight rules and this is not among them. A fresh database never gets
 * it. What is left is the row on registers seeded before it was dropped —
 * which the seeder cannot clear, because seeding only ever writes.
 *
 * ── The nine rules that are NOT touched ─────────────────────────────────────
 *
 * Searching the register for "market" turns up ten rows and only this one is a
 * leftover. The other nine price a BUSINESS THAT IS A MARKET — the graduated
 * tax on privately-owned markets and shopping centres, the fish broker's
 * annual fee by stall count, the Bracket I environmental fee for malls, and
 * the Schedule J garbage fees per stall. They are live revenue-code rules with
 * real sections behind them, and a shopping centre applying for a business
 * permit is assessed by them today. Deleting them because the word matched
 * would quietly stop billing every market operator in the city.
 *
 * ── Reversing it ────────────────────────────────────────────────────────────
 *
 * `down()` restores the row exactly as seeded. Bringing the whole clearance
 * back needs more than this — see the note in ReferenceSeeder, which lists the
 * department, the permit type, the checklist and the form codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleted = DB::table('fee_rules')->where('code', 'market.stall_rental')->delete();

        // Said out loud: a silent destructive migration is one nobody can
        // check afterwards, and "nothing was lost" is a measurement.
        echo "  market.stall_rental: {$deleted} row(s) removed\n";
    }

    public function down(): void
    {
        DB::table('fee_rules')->updateOrInsert(
            ['code' => 'market.stall_rental'],
            [
                'title' => 'Public market stall rental (officer-set, pending Market Code)',
                'section' => 'none in A10-2016',
                'source' => 'A10-2016',
                'office' => 'CMO',
                'group' => 'regulatory',
                'permit_types' => json_encode(['MARKET']),
                'conditions' => json_encode([]),
                'basis' => 'fixed',
                'computation' => json_encode(['type' => 'fixed', 'amount' => 0]),
                'notes' => 'requires_officer: Ordinance A10-2016 contains no public-market stall fee schedule (Chapter V covers only city-owned commercial space leases at P200/sqm/month).',
                'defects' => json_encode([]),
                'requires_officer' => 1,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
