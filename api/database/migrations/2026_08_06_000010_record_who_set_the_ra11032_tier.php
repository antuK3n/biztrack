<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who decided a filing's RA 11032 category — the system, or a named officer.
 *
 * `applications.complexity` already holds the tier and keeps holding it; this
 * adds nothing parallel to it. What it adds is PROVENANCE, and the reason is
 * that the tier stopped being one kind of fact the moment offices were allowed
 * to set it.
 *
 * Until now every value in that column came from `Support\Ra11032::tierFor()`:
 * renewals and amendments are simple, new filings are complex, unless a
 * hard-coded category list plus a capital floor makes them highly technical.
 * That rule is OURS. Nobody at BPLO approved it — it is open question A10 in
 * docs/questions-for-malabon.md, because the statute requires the LGU to
 * publish its own classification in its Citizen's Charter and Malabon has not
 * told us what theirs is. The Analytics Dashboard's RA 11032 compliance rate
 * is therefore measured against a guess.
 *
 * The client's answer is to let the reviewing office set the tier, which is
 * right — and which makes "3 working days" mean two different things in the
 * same column: a deadline the LGU chose, and a deadline we invented on its
 * behalf. An officer opening the review sheet must be able to tell which one
 * they are looking at before they override it, and an auditor reading the
 * compliance rate must be able to tell how much of it rests on our guess.
 * Null here is the honest way to say "nobody has looked at this".
 *
 * Deliberately additive and deliberately nullable. Every one of the existing
 * rows was classified automatically, so null is the correct value for all of
 * them and no backfill is needed or wanted — writing a user id onto them would
 * manufacture a decision nobody made.
 *
 * `complexity_set_at` is separate from `updated_at` because `updated_at` moves
 * for every unrelated write on the filing; the question this answers is when
 * the CATEGORY was last decided, which is what the review sheet shows next to
 * the officer's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            /*
             * `nullOnDelete` rather than a cascade: an account being removed
             * must not take a statutory reclassification off the record with
             * it. The audit row (`application.reclassified`) keeps the full
             * story either way; this column degrades to "set by a person whose
             * account is gone", which is still not "set automatically".
             */
            $table->foreignId('complexity_set_by_user_id')
                ->nullable()
                ->after('complexity')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('complexity_set_at')->nullable()->after('complexity_set_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('complexity_set_by_user_id');
            $table->dropColumn('complexity_set_at');
        });
    }
};
