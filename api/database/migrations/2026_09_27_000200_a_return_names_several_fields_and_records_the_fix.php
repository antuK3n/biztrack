<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A return can name SEVERAL fields, and what the applicant changed is kept.
 *
 * ── Several, not one ─────────────────────────────────────────────────────────
 *
 * Client, 27 September 2026: *"the admin can choose which field is wrong and
 * return it to the business applicant. The business applicant can then comply
 * to those SELECTED FIELDS only."* Plural, and `remarks_target` held one code
 * in a `varchar` the request validated at 120 characters — about four codes'
 * worth, and silently truncating at the database in between.
 *
 * Widened to text and read as a comma-separated list. NOT a child table: every
 * value is a short code the system already owns, there is no per-field
 * ordering, status or history to keep, and a row written before today is a
 * valid list of one — so no data migration, and every past return keeps
 * rendering exactly as it did.
 *
 * ── And the officer has to see what came back ────────────────────────────────
 *
 * *"where can the admin see the newly complied fields?"* Nowhere, before this:
 * a resubmitted filing arrived looking like any other and the officer re-read
 * fifty questions to find the three they had asked about.
 *
 * `application_corrections` is one row per field per round — the code, the
 * value before, the value after. Written at the moment the correction is
 * saved, which is the only moment both halves are known for certain; deriving
 * "before" later would mean trusting a snapshot taken at return time to still
 * describe the row, and a renewal prefill or an officer's own edit in between
 * would make it a lie.
 *
 * One row per ROUND rather than one per field overall, because a filing can be
 * returned more than once and "what changed this time" is the officer's
 * question. `application_id` + `created_at` gives the round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_assignments', function (Blueprint $table) {
            // sqlite ignores varchar lengths, MySQL does not — a five-field
            // return is about 150 characters and would have been cut in half.
            $table->text('remarks_target')->nullable()->change();
        });

        Schema::create('application_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            /*
             * The `form:` code this correction answers, exactly as the officer
             * ticked it. Not a column name: the code is what both sides already
             * speak, and one code can cover a field whose column is named
             * differently (`form:capital_participation` writes
             * `capital_participation_filipino`).
             */
            $table->string('target', 120);
            /*
             * Nullable because a blank is a real answer on both sides — the TIN
             * that was never given, the trade name the applicant cleared. An
             * empty string and "no value" are the same thing to every field
             * here, so null carries both rather than inventing a distinction
             * the form does not have.
             */
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_corrections');

        Schema::table('application_assignments', function (Blueprint $table) {
            $table->string('remarks_target', 120)->nullable()->change();
        });
    }
};
