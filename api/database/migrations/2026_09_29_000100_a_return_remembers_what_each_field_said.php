<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ── A return records what each named field said at the time ──────────────────
 *
 * `application_corrections` is written in exactly one place — the scalar
 * `corrections()` endpoint — so a field the applicant fixed IN THE WIZARD left
 * no record at all. The officer's sheet showed no CORRECTED badge on it and the
 * "Corrected after your return" block never mentioned it, because as far as the
 * database was concerned nothing had been corrected.
 *
 * Half the return targets are in that position by design: a form of
 * organization, a gender, an economic organization, the barangay, the
 * line-of-business table and Section B's four declared figures are all
 * `section` targets, editable only in the wizard. The client asked for the
 * sheet to be consistent across fields; it cannot be while half of them are
 * unrecorded.
 *
 * ── Why a snapshot rather than a diff on save ────────────────────────────────
 *
 * `PUT /applications/{id}` is the wizard's AUTOSAVE. Diffing there would write
 * a correction row per keystroke-batch — a dozen rows saying the trade name
 * went from "A" to "AA" to "AAA" — and the officer wants one answer per field
 * per round, not a replay of the typing.
 *
 * So the value of every named field is captured WHEN THE FILING IS RETURNED,
 * and compared once, at resubmission. One row per field per round, comparing
 * what the officer saw against what came back.
 *
 * `at` rides along with the values because the comparison has to know which
 * correction rows are already accounted for: a scalar fixed on the correction
 * card is recorded by `corrections()` the moment it is saved, and resubmit must
 * not write it a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            /*
             * {"at": "2026-09-29T12:00:00Z", "values": {"form:tin": "123", …}}
             *
             * Nullable and cleared at resubmission: it is the state of one open
             * round, not history. The history is `application_corrections`.
             */
            $table->json('returned_values')->nullable()->after('fee_profile');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('returned_values');
        });
    }
};
