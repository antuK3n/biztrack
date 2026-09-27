<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A permit's status changes get a history of their own.
 *
 * ── The moment `transitionClearance` said to wait for ────────────────────────
 *
 * That method carries a note explaining why the pivot had no history table:
 * *"a second history table would be a third place to keep in step for a
 * timeline nothing renders yet. If the applicant ever needs a per-permit
 * timeline, that is the moment to add one — not now, on the guess that they
 * might."* The client asked for one on 26 September 2026, so this is that
 * moment.
 *
 * ── Why this is a COLUMN and not the second table it warned about ────────────
 *
 * The warning was about a third place to keep in step, and it still applies. So
 * the clearance rows go into `application_status_history` beside the filing's
 * own, separated by `permit_type_id`: null for the filing, set for one permit.
 * One writer shape, one reader shape, one table to reason about.
 *
 * `department_id` has sat on this table unused since the manuscript alignment
 * in July, added as "department handling at that time" and never written by
 * anything. A clearance transition is precisely the event it was reserved for —
 * the office that owns the permit — so it is filled in rather than left as a
 * second unused column beside a new one.
 *
 * ── The backfill, and why it is safe ─────────────────────────────────────────
 *
 * Every clearance transition since the beginning is already recorded, in
 * `audit_logs` under `clearance.status_changed`, carrying the application, the
 * permit type, from, to, the note and who did it. Without a backfill the
 * feature would ship empty for all 27 filings in the register — a timeline
 * panel that says "no history" on a permit that plainly has one.
 *
 * It reads the audit trail and writes history rows; it never writes audit rows,
 * never deletes anything, and skips a pair it has already inserted, so running
 * it twice adds nothing. The audit log remains the system of record and this is
 * a derived read of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_status_history', function (Blueprint $table) {
            $table->foreignId('permit_type_id')->nullable()->after('application_id')
                ->constrained('permit_types')->nullOnDelete();
        });

        /*
         * Indexed on the pair the reader actually asks for: "this filing's rows
         * for this permit, in order". The existing reader — the filing's own
         * timeline — now asks for `permit_type_id is null`, which the same
         * index serves.
         */
        Schema::table('application_status_history', function (Blueprint $table) {
            $table->index(['application_id', 'permit_type_id'], 'ash_app_permit_idx');
        });

        $departmentFor = DB::table('permit_types')
            ->pluck('issuing_department_id', 'id');

        $rows = DB::table('audit_logs')
            ->where('action', 'clearance.status_changed')
            ->orderBy('id')
            ->get(['user_id', 'changes', 'created_at']);

        foreach ($rows as $row) {
            $c = json_decode((string) $row->changes, true);

            if (! is_array($c) || empty($c['application_id']) || empty($c['permit_type_id'])) {
                continue;
            }

            $exists = DB::table('application_status_history')
                ->where('application_id', $c['application_id'])
                ->where('permit_type_id', $c['permit_type_id'])
                ->where('to_status', $c['to'] ?? null)
                ->where('created_at', $row->created_at)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('application_status_history')->insert([
                'application_id' => $c['application_id'],
                'permit_type_id' => $c['permit_type_id'],
                /*
                 * `from` is "not_started" in the audit trail where the pivot had
                 * no status yet. Kept verbatim: it is what the machine recorded,
                 * and rewriting it to null here would make the backfilled rows
                 * disagree with rows written from now on.
                 */
                'from_status' => $c['from'] ?? null,
                'to_status' => $c['to'] ?? null,
                'changed_by_user_id' => $row->user_id,
                'department_id' => $departmentFor[$c['permit_type_id']] ?? null,
                'note' => $c['note'] ?? null,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);
        }
    }

    public function down(): void
    {
        /*
         * The backfilled and the newly written rows go together. They are
         * identified by the column being dropped, so this has to run first.
         */
        DB::table('application_status_history')->whereNotNull('permit_type_id')->delete();

        Schema::table('application_status_history', function (Blueprint $table) {
            $table->dropIndex('ash_app_permit_idx');
            $table->dropConstrainedForeignId('permit_type_id');
        });
    }
};
