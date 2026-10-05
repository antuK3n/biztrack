<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Mayor's / Business Permit expires on 31 December, not 20 January.
 *
 * Ken, 5 October 2026, from Malabon Revenue Code Ch. III art. A (e): it
 * "expires on the thirty-first (31st) of December following date of issuance
 * … renewed within the first twenty (20) days of January". From 17 September
 * to 5 October 2026 BizTrack issued it to 20 January of the year after issue
 * (`RenewalSeason`), so every Business Permit issued then ends on a 20
 * January. This moves each of them to 31 December of the year before: a
 * permit issued in 2026 and ending 20 January 2027 now ends 31 December 2026,
 * which is what the code issues today. The twenty days are the penalty-free
 * renewal window instead.
 *
 * Business Permits only: the five clearances already end on 31 December or a
 * year from renewal, and a clearance that happens to end on 20 January is a
 * renewal's anniversary, not this rule. A Business Permit on any other date
 * (recorded from paper) is not touched.
 *
 * ── Safe to run again, on SQLite or PostgreSQL ─────────────────────────────
 *
 * Rows are picked with whereMonth/whereDay, which the query builder writes
 * for each driver, and each is updated by id, its stored format kept. A
 * moved permit ends on 31 December, so a second run finds nothing to move.
 * Only `valid_until` changes; status is the nightly scan's to set. The count
 * is reported either side (AGENTS.md §2.2).
 *
 * `down()` does not move them back. Which rows came from 20 January is not
 * recorded, and a 31 December permit issued after this ran must not be moved
 * to a 20 January it never had.
 */
return new class extends Migration
{
    public function up(): void
    {
        $type = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($type === null) {
            return;
        }

        $rows = DB::table('permits')
            ->where('permit_type_id', $type)
            ->whereNotNull('valid_until')
            ->whereMonth('valid_until', 1)
            ->whereDay('valid_until', 20)
            ->get(['id', 'valid_until']);

        foreach ($rows as $row) {
            $stored = (string) $row->valid_until;
            $year = (int) substr($stored, 0, 4);

            /*
             * Only the date part is replaced. SQLite holds this column as
             * "2027-01-20 00:00:00" where Eloquent wrote it and PostgreSQL as
             * a date, and the register compares it as text in places; writing
             * a bare date beside full ones would sort it before its own day.
             */
            DB::table('permits')->where('id', $row->id)->update([
                'valid_until' => sprintf('%04d-12-31', $year - 1).substr($stored, 10),
            ]);
        }

        if (PHP_SAPI === 'cli') {
            $total = DB::table('permits')->where('permit_type_id', $type)->count();
            fwrite(STDOUT, "business permit term: {$rows->count()} of {$total} Business Permit(s) moved from 20 January to 31 December".PHP_EOL);
        }
    }

    public function down(): void
    {
        // Not reversible; see the note above.
    }
};
