<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ── "Last opened" has to mean opened ────────────────────────────────────────
 *
 * The drafts list sorts on `updated_at`, and the option was called "Last
 * worked on" for a reason this codebase wrote down: *"'Last worked on' rather
 * than 'Last accessed': the date it reads moves when the draft is SAVED, and
 * opening one to read it saves nothing. Naming it for what it measures is the
 * difference between a label and a small lie."*
 *
 * Client, 29 September 2026, asking for the label to change: *"Change the
 * filter name too to 'Last opened' instead of 'Last worked on' so this means
 * rule changes may happen depending on what the 'Last worked on' does."* —
 * which is exactly right. Renaming alone would turn that note's warning into
 * the bug it was written to prevent, so the measurement changes with the name.
 *
 * `last_opened_at` is stamped when the applicant actually opens the thing: on
 * `ApplicationController::show` for their own draft, and on
 * `WizardDraftController::show` for an unfinished filing. Nothing else touches
 * it — an autosave moves `updated_at` and leaves this alone, so the two now
 * answer the two different questions people ask of a drafts list.
 *
 * Nullable, with no backfill. A draft nobody has opened since this shipped has
 * genuinely never been opened as far as the register knows, and inventing a
 * date from `updated_at` would put a fact in the column that nothing observed.
 * The list falls back for display; the column stays honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('last_opened_at')->nullable()->after('updated_at');
        });

        Schema::table('wizard_drafts', function (Blueprint $table) {
            $table->timestamp('last_opened_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('last_opened_at');
        });

        Schema::table('wizard_drafts', function (Blueprint $table) {
            $table->dropColumn('last_opened_at');
        });
    }
};
