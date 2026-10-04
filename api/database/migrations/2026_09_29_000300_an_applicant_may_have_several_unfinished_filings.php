<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ── One unfinished filing per START, not one per applicant ───────────────────
 *
 * `wizard_drafts` was unique on (user_id, application_type), which quietly
 * meant "an applicant may have one unfinished New Business Permit at a time".
 *
 * The client found what that costs on 29 September 2026: *"I tried clicking
 * the New Business Permit, tick the Data Privacy Act, then left the page (did
 * this 3 times) and only one draft appeared in the drafts."* Three starts, one
 * row — each save replaced the last, and two filings were silently gone.
 *
 * With one row per type there is nowhere to put a second start, so New
 * Business Permit had to either reopen the existing answers (which the client
 * reported first) or overwrite them (which they reported next). Neither is a
 * real choice: these are drafts, and a person may have as many as they like.
 *
 * Dropping the constraint is the whole change. Every row already carries an
 * `id`; nothing used it because the pair addressed the row instead. Now the id
 * does — which is also what lets a Drafts card point at one particular
 * unfinished filing rather than at "whatever your latest new-permit answers
 * happen to be".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wizard_drafts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'application_type']);
            /*
             * Still indexed together: every query here is "this user's
             * unfinished filings", often narrowed by type. Only the UNIQUEness
             * goes.
             */
            $table->index(['user_id', 'application_type']);
        });
    }

    public function down(): void
    {
        Schema::table('wizard_drafts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'application_type']);
            $table->unique(['user_id', 'application_type']);
        });
    }
};
