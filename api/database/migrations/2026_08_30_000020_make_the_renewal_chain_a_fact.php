<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that turn "which permit is this a renewal of?" from a guess into
 * a recorded answer.
 *
 * ## 1. `permits.prior_permit_id` — the permit-to-permit chain
 *
 * A renewal already names the permit it replaces, on
 * `applications.prior_permit_id`. But the permit that COMES OUT of that renewal
 * has never pointed back at the one it succeeded, so continuity between two
 * certificates is currently INFERRED from (business, permit_type, dates) —
 * RenewalOutcomes does exactly that to decide whether a renewal was late, and
 * that late/on-time figure is now what fits the renewal model (Glm via
 * RenewalModelAnalytics).
 *
 * Inference by matching type and dates is fine until a business holds two
 * permits of the same type in the same year, which is precisely what happens
 * when somebody renews late: last year's Mayor's Permit and this year's, same
 * type, overlapping records. At that point the match is a coin toss, and a coin
 * toss is training a model. This column makes the link a fact written at
 * issuance instead.
 *
 * ## 2. `applications.prior_permit_declared_none` — silence is not an answer
 *
 * The renewal flow deliberately allows a null prior permit: in year one most
 * renewals are of permits issued on paper by the old counter process, and those
 * businesses have nothing in the register to point at. The code even says
 * "`null` is a real answer, not an unanswered question".
 *
 * It said so without ever recording WHICH. Null meant both "I have no BizTrack
 * permit" and "nobody ever asked me", and the two are indistinguishable in the
 * column — so the second could reach BPLO wearing the first's clothes. That is
 * how the register ended up with seven renewals of nothing: five of them on
 * businesses that hold no permit at all and were never made to say so, one on a
 * business holding three permits where the question was simply skipped, and one
 * written straight in by DemoSeeder.
 *
 * This flag is the applicant declaring the escape rather than falling through
 * it. Submit now requires one or the other, so the unanswered case cannot
 * recur.
 *
 * ## Safety
 *
 * Both columns are additive and nullable/defaulted, so every existing row stays
 * valid and no writer has to change to keep working. Nothing is created or
 * deleted. The backfill only ever copies a link that already exists on the
 * application, and only when the prior permit belongs to the same business as
 * the permit being linked — a cross-business chain is worse than no chain,
 * because analytics would read it as continuity. Running it twice is a no-op:
 * the second pass only considers rows still null.
 *
 * Verified against the tester register before writing: 5,942 permits, of which
 * 2,722 hang off an application carrying a prior permit, and all 2,722 pass the
 * same-business check. Expect 2,722 chained and 3,220 left null (the `new`
 * filings, which correctly have no predecessor).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('permits', 'prior_permit_id')) {
            Schema::table('permits', function (Blueprint $table) {
                /*
                 * No foreign key constraint. `permits` is self-referencing and
                 * SQLite rebuilds the whole table to add one, which on a file
                 * holding real tester filings is a rewrite this change does not
                 * need — the column is written from a row we have just read out
                 * of this same table, and the index is what the reads want.
                 */
                $table->unsignedBigInteger('prior_permit_id')->nullable()->after('permit_type_id');
                $table->index('prior_permit_id');
            });
        }

        if (! Schema::hasColumn('applications', 'prior_permit_declared_none')) {
            Schema::table('applications', function (Blueprint $table) {
                /*
                 * Defaults false, which reads as "not declared" — the honest
                 * state for every row that predates the question. It does NOT
                 * retroactively assert that those filings had no prior permit.
                 */
                $table->boolean('prior_permit_declared_none')->default(false);
            });
        }

        /*
         * Backfill the chain from the application that issued each permit.
         *
         * Written as a correlated subquery rather than a join-update because
         * SQLite has no UPDATE ... FROM before 3.33 and this has to run on
         * whatever the dev box has. The same-business guard is inside the
         * subquery, so a link that would cross businesses yields null and the
         * row is simply left unchained.
         */
        DB::statement(<<<'SQL'
            update permits
               set prior_permit_id = (
                   select a.prior_permit_id
                     from applications a
                     join permits pp on pp.id = a.prior_permit_id
                    where a.id = permits.application_id
                      and pp.business_id = permits.business_id
               )
             where prior_permit_id is null
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('permits', 'prior_permit_id')) {
            Schema::table('permits', function (Blueprint $table) {
                $table->dropIndex(['prior_permit_id']);
                $table->dropColumn('prior_permit_id');
            });
        }

        if (Schema::hasColumn('applications', 'prior_permit_declared_none')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropColumn('prior_permit_declared_none');
            });
        }
    }
};
