<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close the office reviews that rejected and cancelled filings left open.
 *
 * ── What was wrong ──────────────────────────────────────────────────────────
 *
 * Rejecting a filing (BPLO) and cancelling one (the applicant) moved the
 * filing to its terminal status and left every office's review of it exactly
 * where it was: `pending`, `in_progress` or `returned`, for good. Those rows sat
 * in the offices' For Approval tabs and were counted in every open-backlog
 * figure — Office Performance's "open now", the Reports tab's "still pending".
 * On the copy of the register this was measured on, 106 open reviews belonged
 * to rejected filings: 35 BPLO, 26 BFP, 21 CHO, 14 OBO, 7 CENRO, 3 CPDO.
 *
 * WorkflowService::transition() now closes them as the filing ends. This is
 * the same act, once, over the rows written before it did.
 *
 * ── What it touches ─────────────────────────────────────────────────────────
 *
 * Only `application_assignments` rows that are still open AND whose filing is
 * rejected or cancelled. They become `closed` (AssignmentStatus::Closed);
 * `completed_at` stays null, because nobody completed them — see the enum for
 * why that matters to every turnaround figure. Completed reviews, and reviews
 * of live filings, are not read, let alone written.
 *
 * Idempotent: a second run finds nothing left open on a dead filing. One
 * UPDATE with a subquery, which SQLite and PostgreSQL both run as written.
 *
 * ── Down ────────────────────────────────────────────────────────────────────
 *
 * Does nothing. Re-opening the rows would put dead filings back in the queues,
 * and once the workflow has closed more rows by itself the two can no longer
 * be told apart. The `assignment.closed` audit rows name the ones the workflow
 * closed after this ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('application_assignments')
            ->whereIn('status', ['pending', 'in_progress', 'returned'])
            ->whereIn('application_id', DB::table('applications')
                ->select('id')
                ->whereIn('status', ['rejected', 'cancelled']))
            ->update(['status' => 'closed', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately empty; see the note above.
    }
};
