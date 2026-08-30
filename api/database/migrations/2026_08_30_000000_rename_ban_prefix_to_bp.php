<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `BAN-YYYY-NNNN` → `BP-YYYY-NNNN` on every business account number.
 *
 * The value, not the column. `businesses.ban` keeps its name — Business Account
 * Number is what a BPLO counter calls it, and renaming a column referenced
 * across the codebase buys a user nothing. What had to change is the three
 * letters an owner reads on their own record.
 *
 * This system has a real blacklist: `businesses.status` sits next to this
 * column and the admin side has a blacklist modal. So "BAN" named two opposite
 * things at once, and the meaning an owner meets first — on a number printed
 * against their own business — is the sanction. That is not a subtle misread;
 * it is the plain English sense of the word.
 *
 * ## Why the existing rows are rewritten rather than left alone
 *
 * Numbering::ban() finds the next sequence by scanning for rows already
 * carrying this year's prefix. Change the generator alone and the scan matches
 * nothing, so the counter restarts at 0001 and the register ends up holding
 * both `BAN-2026-0001` and `BP-2026-0001` — two numbers, two formats, one
 * business each, and no way for a clerk to tell which is current. The rename
 * has to be total or not at all.
 *
 * ## Safety
 *
 * Only rows whose `ban` actually starts `BAN-` are touched, and the numeric
 * tail is preserved exactly, so `BAN-2026-0042` becomes `BP-2026-0042` and
 * nothing is renumbered. Running it twice is a no-op: the second pass matches
 * nothing. `ban` is unique, and swapping one prefix for another across the
 * whole column preserves that. No row is created or deleted.
 *
 * The register holds real tester filings, so this is a string rewrite on a
 * format we generate — not a renumbering, and reversible below.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('businesses')
            ->where('ban', 'like', 'BAN-%')
            ->update(['ban' => DB::raw("'BP-' || substr(ban, 5)")]);
    }

    /**
     * Reversible on purpose. If BPLO confirms they print "BAN" on something the
     * owner receives, the right answer is to change the on-screen label and put
     * this back — so the path back stays open rather than being a rewrite.
     */
    public function down(): void
    {
        DB::table('businesses')
            ->where('ban', 'like', 'BP-%')
            ->update(['ban' => DB::raw("'BAN-' || substr(ban, 4)")]);
    }
};
