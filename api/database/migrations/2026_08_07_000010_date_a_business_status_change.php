<?php

use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an admin last changed a business's moderation status.
 *
 * `businesses.status` (active / flagged / suspended / blacklisted) has been
 * settable since day one and has never reached a chart. It blocks new filings
 * — `Business::isBlockedFromApplying()` — and stops there. The client asked for
 * a blacklisting to mean something on the dashboard, and chose the Business
 * Closure Trend: a blacklisted business is one the LGU has struck off, so it
 * belongs beside the closures already on that chart. Today that chart draws
 * only soft-deleted rows, all 61 of which came out of a seeder, because nothing
 * in the product can soft-delete a business. On a real deployment it is a flat
 * line at zero.
 *
 * A trend needs a DATE, and the status column had none. That is the whole
 * reason this column exists.
 *
 * `updated_at` cannot be that date. It moves for every unrelated write on the
 * business — a phone number, a lessor's name — so a business blacklisted in
 * March whose address was corrected in July would plot as a July closure.
 * BusinessGrowthAnalytics' own docblock already rejects `updated_at` for
 * exactly this reason when it explains why a closure is dated by `deleted_at`;
 * the same objection applies here and gets the same answer, a column of its
 * own. `complexity_set_at` on `applications` is the same pattern for the same
 * reason.
 *
 * NULLABLE, AND NULL MEANS SOMETHING
 *
 * Null is "nobody recorded when this status was set". A blacklisted business
 * with a null date still counts as Closed in the Business Status Summary,
 * because that panel is a snapshot of how things stand today and needs no date
 * to say so. It cannot appear in the Closure Trend, because there is no month
 * to put it in. Inventing one — falling back to `updated_at`, or to
 * `created_at`, or to today — would draw a closure in a month where nothing
 * happened, which is the failure this column was added to avoid.
 *
 * THE BACKFILL
 *
 * `audit_logs` has recorded every status change as `business.status_changed`
 * with from/to and a timestamp, so for the businesses that have one the date
 * already exists and only needs copying across. There are four such rows on the
 * dev register. Two of them are QA sweeps that wrote active -> active, and
 * those are skipped: no status changed, so no status-change date. That matches
 * what BusinessStatusController now writes going forward, which is the point —
 * a backfill that recorded a change the register never made would be seeding
 * the trend with a fiction.
 *
 * The rest of the register keeps null. Every one of those businesses is
 * `active`, so none of them can reach the Closure Trend anyway; where a
 * blacklisting predates the audit log, the honest answer is that the month is
 * not known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        /*
         * Walked in PHP rather than done as a correlated subquery: `changes` is
         * a JSON column and the from/to comparison that identifies a real
         * change is not portable between SQLite and MySQL. There are four rows.
         */
        $latest = [];
        $rows = DB::table('audit_logs')
            ->where('action', 'business.status_changed')
            ->where('auditable_type', Business::class)
            ->whereNotNull('auditable_id')
            ->orderBy('id')
            ->get(['auditable_id', 'changes', 'created_at']);

        foreach ($rows as $row) {
            $changes = json_decode((string) $row->changes, true);
            if (! is_array($changes) || ($changes['from'] ?? null) === ($changes['to'] ?? null)) {
                continue;
            }
            // Ordered by id, so the last write for a business wins.
            $latest[(int) $row->auditable_id] = $row->created_at;
        }

        foreach ($latest as $businessId => $changedAt) {
            DB::table('businesses')
                ->where('id', $businessId)
                ->update(['status_changed_at' => $changedAt]);
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('status_changed_at');
        });
    }
};
