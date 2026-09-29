<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ── The other `remarks_target` was never widened ────────────────────────────
 *
 * On 27 September 2026 `application_assignments.remarks_target` became `text`,
 * for a reason its own migration states: *"sqlite ignores varchar lengths,
 * MySQL does not — a five-field return is about 150 characters and would have
 * been cut in half."*
 *
 * The identical column on `application_permit_types` was left at `varchar`,
 * and that is the one an OFFICE writes when it returns its own permit. Eight
 * ticked checklist rows is well past 255 characters, so on MySQL the list
 * would be chopped mid-code — `…APPLICANT_DECLA` — and `targetsInclude` then
 * matches the wrong rows or none, while the officer's remarks still describe
 * all eight.
 *
 * Nothing shows on SQLite, which is why it survived: the development database
 * ignores the limit and stores the whole string.
 *
 * `down()` puts the length back rather than leaving the column wide. A
 * rollback that quietly keeps the new capacity is a rollback that did not
 * happen, and the next migration to assume `varchar` here would find `text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->text('remarks_target')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->string('remarks_target')->nullable()->change();
        });
    }
};
