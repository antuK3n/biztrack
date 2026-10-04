<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Retire `awaiting_other_permits`.
 *
 * ── What the client asked for ───────────────────────────────────────────────
 *
 * 4 October 2026: *"Why is 'awaiting other permit' still here? Completely wipe
 * out all of them (we no longer need that status)."*
 *
 * It could go because it never gated anything. The Mayor's Permit is released
 * at PAYMENT — `WorkflowService::onPaymentCompleted` calls
 * `releaseOutcomePermit` on both branches — so the status named the period
 * AFTER the certificate was handed over, not a wait before it. Every filing
 * that stood there now stands at `approved`, which is where payment leaves it.
 *
 * ── The part that is not a rename ───────────────────────────────────────────
 *
 * The status had a second job nobody had written down: it was the thing that
 * said the filing was still OPEN. `approved` is terminal, and thirteen guards
 * read `ApplicationStatus::isTerminal()` to decide whether an office may still
 * act — including the one that keeps the clearance stage unlocked. Move a
 * gathering filing to `approved` without more and the applicant can no longer
 * apply for the other permits they are waiting on.
 *
 * So that question moved to the ROW: `Application::isDecided()` reads
 * `decided_at`, which is written in the same breath as the last permit is
 * granted. Open is `approved` with `decided_at` null.
 *
 * ── Which makes the ORDER of the two steps load-bearing ─────────────────────
 *
 * Step 1 backfills `decided_at` on filings already at `approved` that have
 * none. Under the old model the status alone meant decided, so nothing
 * depended on the column being filled, and on the register this ran against
 * seven rows had it null — one real filing and six synthetic prior-application
 * records written by `SeedExpiringDemoBusinesses` to hang demo permits off.
 * Left alone, every one of them would have read as a live gathering filing the
 * moment `isDecided()` started asking.
 *
 * Step 2 then converts the genuinely-gathering filings and deliberately leaves
 * their `decided_at` null.
 *
 * Backfill first. Converting first would stamp the gathering filings with a
 * decision date they have not reached, closing six live applications.
 *
 * ── What is deliberately NOT touched ────────────────────────────────────────
 *
 * `application_status_history`. Those rows are the record of what actually
 * happened to filings that passed through the status, and rewriting an audit
 * trail to tidy a label is not a trade worth making. The web names them
 * through `RETIRED_STATUS_LABELS`, so an old timeline reads "Approved" — the
 * label the status carried while it was live — rather than resurrecting the
 * words this change was asked to remove.
 */
return new class extends Migration
{
    private const RETIRED = 'awaiting_other_permits';

    public function up(): void
    {
        $before = [
            'awaiting' => DB::table('applications')->where('status', self::RETIRED)->count(),
            'approved' => DB::table('applications')->where('status', 'approved')->count(),
            'approved_undated' => DB::table('applications')
                ->where('status', 'approved')->whereNull('decided_at')->count(),
        ];

        /*
         * Step 1 — a decision date for filings decided before the column
         * mattered. Read off the history row that moved the filing to
         * `approved`, which is the real instant; `updated_at` is the fallback
         * for a filing whose history predates that table carrying the move.
         */
        $undated = DB::table('applications')
            ->where('status', 'approved')
            ->whereNull('decided_at')
            ->pluck('updated_at', 'id');

        foreach ($undated as $id => $updatedAt) {
            $decidedAt = DB::table('application_status_history')
                ->where('application_id', $id)
                ->where('to_status', 'approved')
                ->whereNull('permit_type_id')
                ->orderByDesc('created_at')
                ->value('created_at');

            DB::table('applications')
                ->where('id', $id)
                ->update(['decided_at' => $decidedAt ?? $updatedAt ?? now()]);
        }

        /* Step 2 — the gathering filings, whose `decided_at` stays null. */
        $converted = DB::table('applications')
            ->where('status', self::RETIRED)
            ->update(['status' => 'approved']);

        $after = [
            'awaiting' => DB::table('applications')->where('status', self::RETIRED)->count(),
            'approved' => DB::table('applications')->where('status', 'approved')->count(),
            'approved_undated' => DB::table('applications')
                ->where('status', 'approved')->whereNull('decided_at')->count(),
        ];

        /*
         * Counted either side and printed, because this edits rows in a
         * register rather than a schema, and the run log is the only place a
         * reviewer can check the arithmetic: `approved` should rise by exactly
         * the number converted, and `approved_undated` should end equal to it.
         */
        echo sprintf(
            "  retire awaiting_other_permits: %d converted | awaiting %d→%d | approved %d→%d | undated %d→%d\n",
            $converted,
            $before['awaiting'], $after['awaiting'],
            $before['approved'], $after['approved'],
            $before['approved_undated'], $after['approved_undated'],
        );
    }

    /**
     * Put the gathering filings back.
     *
     * Faithful for the status: after `up()`, `approved` with a null
     * `decided_at` is exactly the set this converted, because step 1 dated
     * every other one. The backfill itself is NOT undone — which rows were
     * null is not recorded anywhere, and a date on a decided filing is right
     * whichever model is in force.
     */
    public function down(): void
    {
        $n = DB::table('applications')
            ->where('status', 'approved')
            ->whereNull('decided_at')
            ->update(['status' => self::RETIRED]);

        echo sprintf("  restore awaiting_other_permits: %d restored\n", $n);
    }
};
