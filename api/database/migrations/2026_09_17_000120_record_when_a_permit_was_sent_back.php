<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When an office last sent a permit back to the applicant.
 *
 * ── Why a column and not `updated_at` ─────────────────────────────────────
 *
 * The screens need to say "CPDO asked for changes 3 days ago" — on the
 * applicant's card, so they know how long they have been sitting on it, and on
 * the officer's queue row, so the office can see who has gone quiet and ring
 * them. That is the client's decision of 17 September 2026: show the elapsed
 * time, invent no deadline. RA 11032 fixes the OFFICE's clock, not the
 * citizen's, and Malabon has not given us a Citizen's Charter response window
 * (open question A10), so a due date here would be a deadline no ordinance
 * backs.
 *
 * `updated_at` cannot answer it. Every write to the pivot moves it — a mode
 * change, a submitted_at stamp, a remarks edit — so a permit returned in August
 * and touched this morning would report "returned today". A column that means
 * one thing is the only honest way to measure one thing.
 *
 * `decided_at` cannot answer it either, and deliberately is not reused: that is
 * when the permit was DECIDED, which a return is not. A returned permit has no
 * decision on it; that is the whole point of it coming back.
 *
 * ── It is not cleared on resubmission ─────────────────────────────────────
 *
 * The stamp survives the applicant fixing the sheet. While the permit is
 * Returned it answers "how long has this been waiting"; afterwards it is the
 * record that this permit was sent back once, which is what an officer
 * re-reading it wants to know. A second return overwrites it, because the
 * question the screens ask is always about the LATEST one.
 *
 * Nullable with no backfill: no permit on the register has ever been returned
 * through a path that could have recorded this, and inventing a date would put
 * a false "waiting 40 days" on a real filing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable()->after('remarks_target');
        });
    }

    public function down(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->dropColumn('returned_at');
        });
    }
};
