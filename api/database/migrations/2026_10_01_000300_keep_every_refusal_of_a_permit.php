<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep every refusal of a permit, not only the latest.
 *
 * ── What was lost ───────────────────────────────────────────────────────────
 *
 * An office refusing a clearance stamps `application_permit_types.rejected_at`
 * (migration of 24 September 2026). The applicant may then apply again, and if
 * the office refuses a second time the stamp is overwritten: the first refusal
 * no longer has a date anywhere a report can read. The Reports tab counted
 * refusals by that column, so a permit refused in September and again in
 * October counted once, in October, and September's report lost one after the
 * fact.
 *
 * ── What this adds ──────────────────────────────────────────────────────────
 *
 * One row per refusal, written by WorkflowService::rejectClearance beside the
 * stamp. The stamp stays: it is what the office's banner reads ("refused
 * before"), and that wants the latest.
 *
 * Backfilled from what the register still holds:
 *
 *  - every `rejected_at` on the pivot — the latest refusal of each permit;
 *  - every earlier refusal the audit log recorded (`clearance.status_changed`
 *    to `rejected`, written in the same transaction as each stamp). Only those
 *    can recover a refusal whose stamp was overwritten.
 *
 * A refusal is identified by its permit row and its second, so the two
 * sources do not double-count one refusal and a second run adds nothing.
 * Reads and inserts only; nothing existing is changed. Safe on SQLite and
 * PostgreSQL: plain selects, JSON decoded in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clearance_refusals')) {
            Schema::create('clearance_refusals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_permit_type_id')->constrained('application_permit_types')->cascadeOnDelete();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('permit_type_id')->constrained('permit_types');
                $table->timestamp('refused_at')->index();
                $table->text('reason')->nullable();
                $table->text('remedy')->nullable();
                $table->foreignId('refused_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        $known = [];
        foreach (DB::table('clearance_refusals')->get(['application_permit_type_id', 'refused_at']) as $row) {
            $known[self::key($row->application_permit_type_id, $row->refused_at)] = true;
        }

        $rows = [];
        $add = function (object $pivot, string $at, ?string $reason, ?string $remedy, ?int $by) use (&$known, &$rows) {
            $key = self::key($pivot->id, $at);
            if (isset($known[$key])) {
                return;
            }
            $known[$key] = true;
            $rows[] = [
                'application_permit_type_id' => $pivot->id,
                'application_id' => $pivot->application_id,
                'permit_type_id' => $pivot->permit_type_id,
                'refused_at' => CarbonImmutable::parse($at)->toDateTimeString(),
                'reason' => $reason,
                'remedy' => $remedy,
                'refused_by_user_id' => $by,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        };

        $pivots = DB::table('application_permit_types')->get(['id', 'application_id', 'permit_type_id', 'rejected_at', 'rejection_note', 'rejection_remedy'])->keyBy('id');

        foreach ($pivots as $pivot) {
            if ($pivot->rejected_at !== null) {
                $add($pivot, (string) $pivot->rejected_at, $pivot->rejection_note, $pivot->rejection_remedy, null);
            }
        }

        $audits = DB::table('audit_logs')
            ->where('auditable_type', 'App\\Models\\ApplicationPermitType')
            ->where('action', 'clearance.status_changed')
            ->orderBy('id')
            ->get(['auditable_id', 'changes', 'user_id', 'created_at']);

        foreach ($audits as $audit) {
            $changes = json_decode((string) $audit->changes, true);
            $pivot = $pivots[(int) $audit->auditable_id] ?? null;
            if ($pivot === null || ! is_array($changes) || ($changes['to'] ?? null) !== 'rejected' || $audit->created_at === null) {
                continue;
            }
            $add($pivot, (string) $audit->created_at, $changes['note'] ?? null, null, $audit->user_id === null ? null : (int) $audit->user_id);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('clearance_refusals')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_refusals');
    }

    private static function key(mixed $pivotId, mixed $at): string
    {
        return $pivotId.'|'.CarbonImmutable::parse((string) $at)->format('Y-m-d H:i:s');
    }
};
