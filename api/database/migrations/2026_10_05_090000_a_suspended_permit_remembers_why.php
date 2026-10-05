<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A suspended permit remembers when, why, and for which permit.
 *
 * The client, 5 October 2026, asked what a paused Business Permit should do
 * and chose *"Explain it and chase it"*: *"Show WHY it is suspended and WHICH
 * office caused it — on the permit page, the Track row and the public QR page;
 * the PDF prints SUSPENDED; remind the owner every 7 days; after 30 days
 * unresolved BPLO sees it in a list and may revoke it with a reason."*
 *
 * Until now the cause lived only in the audit row, which no screen reads, and
 * `restorePermitsForBusiness` argued that a cause column was not needed to
 * DECIDE reinstatement. That still holds — reinstatement still asks the filing,
 * not these columns. These exist to EXPLAIN and to COUNT DAYS, which the audit
 * trail cannot do cheaply on a list of thousands.
 *
 *   suspended_at                  when it stopped being Active (the 7-day
 *                                 reminder and the 30-day list count from it)
 *   suspension_reason             the office's refusal or the visit's findings
 *   suspended_for_permit_type_id  the permit whose refusal caused it; null when
 *                                 the cause was not one permit (a sanctioned
 *                                 business, BPLO rejecting the filing)
 *   suspension_reminded_at        the last 7-day reminder, so a scan run twice
 *                                 in a window sends once
 *
 * ── Backfill ─────────────────────────────────────────────────────────────────
 *
 * A permit already Suspended takes its date, reason and refused permit from its
 * LATEST `permit.suspended` / `permit.suspended_on_rejection` audit row — the
 * words recorded at the time, copied, never composed. No audit row, nothing is
 * written. On the live register on 5 October 2026 that was one permit,
 * MP-2026-000006 (a failed Sanitary visit); its audit row names no permit type,
 * so its office stays unnamed rather than guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permits', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('revoked_reason');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
            /*
             * No foreign key constraint, as with `prior_permit_id`: SQLite
             * rebuilds the whole table to add one, and the rebuild fails on
             * the `report_permits` view that reads it. Only WorkflowService
             * writes this, from a PermitType it has just loaded.
             */
            $table->unsignedBigInteger('suspended_for_permit_type_id')->nullable()->after('suspension_reason');
            $table->index('suspended_for_permit_type_id');
            $table->timestamp('suspension_reminded_at')->nullable()->after('suspended_for_permit_type_id');
        });

        $suspended = DB::table('permits')->where('status', 'suspended')->pluck('id');
        $filled = 0;

        foreach ($suspended as $id) {
            $audit = DB::table('audit_logs')
                ->where('auditable_type', 'App\Models\Permit')
                ->where('auditable_id', $id)
                ->whereIn('action', ['permit.suspended', 'permit.suspended_on_rejection'])
                ->orderByDesc('id')
                ->first();
            if ($audit === null) {
                continue;
            }

            $changes = json_decode((string) $audit->changes, true) ?: [];
            $typeId = $changes['because_permit_type_id'] ?? null;
            $reason = isset($changes['reason']) ? mb_substr((string) $changes['reason'], 0, 500) : null;

            DB::table('permits')->where('id', $id)->update([
                'suspended_at' => $audit->created_at,
                'suspension_reason' => $reason,
                'suspended_for_permit_type_id' => $typeId !== null
                    && DB::table('permit_types')->where('id', $typeId)->exists() ? $typeId : null,
            ]);
            $filled++;
        }

        // Report the count either side (AGENTS.md §2.2): rows are only updated.
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "suspension backfill: {$filled} of {$suspended->count()} suspended permit(s) dated from the audit log".PHP_EOL);
        }
    }

    public function down(): void
    {
        Schema::table('permits', function (Blueprint $table) {
            $table->dropIndex(['suspended_for_permit_type_id']);
            $table->dropColumn([
                'suspended_at', 'suspension_reason', 'suspended_for_permit_type_id', 'suspension_reminded_at',
            ]);
        });
    }
};
