<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three columns shorter than what the application writes into them.
 *
 * `string()` is varchar(255). SQLite never enforces the length, which is why
 * none of this showed on dev or demo; PostgreSQL does, and refuses the whole
 * INSERT — so on the production database the officer's action failed with a
 * 500, not just the one column:
 *
 *   application_status_history.note   The timeline note. Rejecting a filing
 *                                     or returning it writes the officer's
 *                                     reason here, and the reason is validated
 *                                     up to 1,000 characters.
 *   app_notifications.body            The bell. Its body quotes that same
 *                                     reason, or an office's refusal and its
 *                                     remedy, inside a sentence of its own.
 *                                     Measured: an office refusing a permit
 *                                     with an ordinary two-line reason failed.
 *   officer_requests.meeting_link     Validated to 500 characters; a Teams
 *                                     invitation link is routinely longer than
 *                                     255.
 *
 * All three become text, which PostgreSQL stores exactly as it stores varchar.
 * The validators stay the limit. Found by the first run of the suite against
 * PostgreSQL 16 (phpunit.pgsql.xml).
 *
 * On SQLite `->change()` rebuilds each table; none of the three is read by a
 * reporting view, and App\Support\ReportViews steps the views aside anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_status_history', fn (Blueprint $t) => $t->text('note')->nullable()->change());
        Schema::table('app_notifications', fn (Blueprint $t) => $t->text('body')->change());
        Schema::table('officer_requests', fn (Blueprint $t) => $t->text('meeting_link')->nullable()->change());
    }

    /**
     * Back to varchar(255). On PostgreSQL this refuses, rather than truncates,
     * while any row holds more than 255 characters — which is the right way
     * round for a reason somebody wrote.
     */
    public function down(): void
    {
        Schema::table('application_status_history', fn (Blueprint $t) => $t->string('note')->nullable()->change());
        Schema::table('app_notifications', fn (Blueprint $t) => $t->string('body')->change());
        Schema::table('officer_requests', fn (Blueprint $t) => $t->string('meeting_link')->nullable()->change());
    }
};
