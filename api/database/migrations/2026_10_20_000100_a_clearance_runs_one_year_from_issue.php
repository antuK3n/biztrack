<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five clearances run one year from the day they are issued.
 *
 * Client, 20 October 2026: *"BPLO Mayor's / Business Permit - dec 31; the
 * rest 1 year on the date issued"* — Zoning, Sanitary, FSIC, Occupancy and
 * CENRO. From 1 October 2026 BizTrack anchored a clearance's FIRST issue to
 * 31 December of that year (`RenewalSeason::endOfCalendarYearFor`), so a
 * Zoning Clearance issued on 6 October ended on 31 December: a twelve-week
 * certificate, and the register, the certificate and the QR verification page
 * all said so because they all read this column. This moves each of those to
 * the anniversary of its `valid_from`, which is what the code issues today.
 *
 * Picked narrowly: a clearance the system issued (it has an application),
 * ending on 31 December of the same year it started. A renewal already runs a
 * year and ends on its anniversary, and a permit recorded from paper (no
 * application) keeps the date printed on the paper. An amendment's copy
 * carries its prior permit's `valid_from` and so moves with it.
 *
 * ── Safe to run again, on SQLite or PostgreSQL ─────────────────────────────
 *
 * A moved permit no longer ends on 31 December of its starting year, so a
 * second run finds nothing. Each row is updated by id with only the date part
 * replaced, keeping the stored format (see 2026_10_05_100000 for why). Status
 * is the nightly scan's to set and is not touched. The count is reported
 * (AGENTS.md §2.2).
 *
 * `down()` does not move them back: which rows were anchored is not recorded,
 * and a clearance issued after this ran must not be cut short.
 */
return new class extends Migration
{
    public function up(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');

        $rows = DB::table('permits')
            ->when($business !== null, fn ($q) => $q->where('permit_type_id', '!=', $business))
            ->whereNotNull('application_id')
            ->whereNotNull('valid_from')
            ->whereNotNull('valid_until')
            ->whereMonth('valid_until', 12)
            ->whereDay('valid_until', 31)
            ->get(['id', 'valid_from', 'valid_until']);

        $moved = 0;
        foreach ($rows as $row) {
            $from = CarbonImmutable::parse(substr((string) $row->valid_from, 0, 10));
            $until = (string) $row->valid_until;

            if ((int) substr($until, 0, 4) !== $from->year) {
                continue;
            }

            DB::table('permits')->where('id', $row->id)->update([
                'valid_until' => $from->addYear()->toDateString().substr($until, 10),
            ]);
            $moved++;
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "clearance term: {$moved} clearance(s) moved from 31 December to a year from issue".PHP_EOL);
        }
    }

    public function down(): void
    {
        // Not reversible; see the note above.
    }
};
