<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ── Which return a note belongs to ──────────────────────────────────────────
 *
 * `application_return_notes` has held one note per returned FIELD since 27
 * September 2026, and only BPLO's main-form return ever wrote to it.
 *
 * The five offices force the same typing and then lose it.
 * `AssignmentController` accepts `remarks_notes`, `returnAssignment` carries
 * them, and both clearance branches drop them on the floor —
 * `returnClearance()` has no parameter to receive them. The officer UI makes
 * this worse than a missed feature: its Return button stays disabled until
 * EVERY ticked row has a note, so an office is compelled to write three
 * notes and then gets one paragraph with all three run together, while the
 * rows they belong to show nothing.
 *
 * ── Why a column rather than just writing rows ──────────────────────────────
 *
 * The table is keyed by application, and `returnMainForm` clears it with
 * `where('application_id', …)->delete()` before writing its own set — because
 * a note left over from a previous round sits under a field this round is not
 * about. Write office notes into the same table unscoped and BPLO's next
 * return silently deletes CHO's, and the other way round.
 *
 * `permit_type_id` says which return owns the row: null for the main form,
 * the permit for an office's. Each return then replaces only its own set, and
 * the two cannot reach across.
 *
 * Nullable, and no backfill: every existing row was written by the main-form
 * return, which is exactly what null means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_return_notes', function (Blueprint $table) {
            /*
             * `nullOnDelete`, not cascade. A permit type is reference data and
             * is not deleted in practice, but if one ever were, the note is
             * still a true record of something an officer wrote — orphaning it
             * to the main form is wrong, and deleting the applicant's
             * instructions to tidy a lookup table is worse.
             */
            $table->foreignId('permit_type_id')
                ->nullable()
                ->after('application_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['application_id', 'permit_type_id']);
        });
    }

    public function down(): void
    {
        Schema::table('application_return_notes', function (Blueprint $table) {
            $table->dropIndex(['application_id', 'permit_type_id']);
            $table->dropConstrainedForeignId('permit_type_id');
        });
    }
};
