<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ── The offices could not see what changed ─────────────────────────────────
 *
 * BPLO has had this since 29 September 2026: `applications.returned_values`
 * records what each named field said when the filing went back, `resubmit`
 * compares once, and the officer's sheet shows CORRECTED with was → now.
 *
 * `returnClearance` does neither, so an office re-reading a resubmitted sheet
 * sees exactly the sheet it sent back, with nothing marking the row that
 * moved. That is the complaint that started this whole feature, still true on
 * the office half.
 *
 * ── Two columns, mirroring the pair that already works ─────────────────────
 *
 * `returned_state` is the office's equivalent of `returned_values`: what each
 * ticked row held at the moment of the return, cleared when the sheet comes
 * back. Per permit, because two offices can have a filing returned at once and
 * each is asking about its own rows.
 *
 * `application_corrections.permit_type_id` says which return a recorded change
 * belongs to — null for BPLO's main form, the permit for an office's. The
 * alternative was a second corrections table, which would mean two ways to ask
 * "what did the applicant change" and two screens' worth of readers to keep in
 * step. Same reasoning as `application_return_notes.permit_type_id`, added an
 * hour earlier for the notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            /*
             * {"at": "…", "values": {"ZONING_LEASE_TITLE": "lease.pdf", …}}
             *
             * Display text, not identity: it becomes `old_value` on a
             * correction the officer reads. The same shape and the same
             * reasoning as `applications.returned_values`.
             */
            $table->json('returned_state')->nullable()->after('remarks_target');
        });

        Schema::table('application_corrections', function (Blueprint $table) {
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
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->dropColumn('returned_state');
        });

        Schema::table('application_corrections', function (Blueprint $table) {
            $table->dropIndex(['application_id', 'permit_type_id']);
            $table->dropConstrainedForeignId('permit_type_id');
        });
    }
};
