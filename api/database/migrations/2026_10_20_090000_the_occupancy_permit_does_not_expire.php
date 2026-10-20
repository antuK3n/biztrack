<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Occupancy Permit is issued once and never expires (client, 20 October
 * 2026): *"It is stated to us that it is only annual inspection instead of
 * annual renewal (like the other permits)."* Asked what to do with the ones
 * already on the register, the client chose *"Make them all permanent"*.
 *
 * So an Occupancy Permit the nightly scan flipped to Expired comes back to
 * Active. Its `valid_until` is left as it is: it now reads as the date its
 * first yearly inspection falls due, and one already past opens that
 * inspection for OBO on the next scan (App\Support\AnnualInspection).
 *
 * Only Expired moves. Revoked, suspended, superseded and retired are decisions
 * somebody made and stay as they are. On 20 October 2026 the live register
 * held 10 Occupancy Permits, all Active, so this changed no row there; it is
 * here for a copy of the register that ran the scan longer.
 *
 * No `down()` rewrite: which rows were Expired is not kept, and putting back
 * an expiry the client has abolished would be wrong anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        $occupancy = DB::table('permit_types')->where('code', 'OCCUPANCY')->pluck('id');
        if ($occupancy->isEmpty()) {
            return;
        }

        DB::table('permits')
            ->whereIn('permit_type_id', $occupancy)
            ->where('status', 'expired')
            ->update(['status' => 'active', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Intentionally empty — see the note above.
    }
};
