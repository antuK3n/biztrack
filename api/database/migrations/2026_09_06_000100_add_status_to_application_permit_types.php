<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every requested permit its own status, and move the application's
 * statuses onto the new machine (docs/application-flow-2026-09.md).
 *
 * `application_permit_types` was the bare M:N pivot — application_id,
 * permit_type_id, timestamps. It is the natural home for a per-permit status
 * because it is already exactly one row per (filing, permit) and nothing else
 * in the schema is. The alternative considered and rejected was hanging the
 * status off `application_assignments`, which is keyed per DEPARTMENT: that
 * works only while every permit has its own office, and it silently breaks the
 * day one office issues two permits.
 *
 * ── The data move ─────────────────────────────────────────────────────────
 *
 * The live register holds 4 applications (2 approved, 1 draft, 1 under_review)
 * and 6 pivot rows, counted immediately before writing this. Small enough that
 * every row is accounted for below rather than swept by a default.
 *
 * `submitted`, `under_review` and `for_inspection` no longer exist on the
 * application. Their rows map to:
 *
 *  - submitted      → for_approval           BPLO had not read it yet either way
 *  - under_review   → awaiting_other_permits paid, offices working — the same
 *                     fact the old status carried, under the name the new flow
 *                     gives it
 *  - for_inspection → awaiting_other_permits ditto; which permit is at the
 *                     inspection stage is now the pivot row's business, and
 *                     that is derived below
 *
 * Nothing maps to `for_final_approval`. It is reached by
 * `WorkflowService::refreshReadiness()` when the last required permit is
 * approved, and computing it here would mean re-deriving "required" for
 * historical rows whose permit set predates the requirement rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = [
            'applications' => DB::table('applications')->count(),
            'pivot' => DB::table('application_permit_types')->count(),
            'permits' => DB::table('permits')->count(),
        ];

        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->string('status')->default(ClearanceStatus::NotStarted->value)->after('permit_type_id');

            /*
             * How the applicant satisfied this permit: filled the office's form
             * (`apply`) or uploaded the one they already hold (`upload`).
             *
             * The office needs it to know what to render — an upload has no
             * form to read, only an image — and it is null until they choose,
             * which is what `not_started` looks like from the other side.
             *
             * It deliberately does NOT affect the fee. The client's rule is that
             * the bill charges for a permit either way, because the fee covers
             * the inspection and an uploaded permit is inspected too.
             */
            $table->string('mode')->nullable()->after('status');

            $table->timestamp('submitted_at')->nullable();   // applicant applied or uploaded
            $table->timestamp('decided_at')->nullable();     // office approved or rejected
            $table->text('remarks')->nullable();             // office's note on a return
            $table->text('rejection_reason')->nullable();

            $table->index(['permit_type_id', 'status']);
        });

        /*
         * Derive each existing pivot row's status from what actually happened to
         * it, not from a blanket default.
         *
         * A permit row EXISTS for the pair → that permit was issued → Approved.
         * That is the only fact in the old schema that unambiguously says a
         * permit finished, and it is exactly what `approveAndIssue()` wrote.
         *
         * Everything else becomes NotStarted, including rows on the
         * under_review filing. Under the old flow the applicant never chose
         * apply-or-upload — every requested permit was routed at once — so
         * there is no honest way to say one of those rows is "for approval"
         * under a machine whose ForApproval means the applicant has submitted
         * that office's form. NotStarted is the true statement: the applicant
         * has not yet done the thing the new flow asks of them.
         */
        $issued = DB::table('permits')
            ->select('application_id', 'permit_type_id')
            ->distinct()
            ->get();

        foreach ($issued as $row) {
            DB::table('application_permit_types')
                ->where('application_id', $row->application_id)
                ->where('permit_type_id', $row->permit_type_id)
                ->update([
                    'status' => ClearanceStatus::Approved->value,
                    'mode' => 'apply',
                    'decided_at' => DB::raw('updated_at'),
                ]);
        }

        $statusMap = [
            'submitted' => ApplicationStatus::ForApproval->value,
            'under_review' => ApplicationStatus::AwaitingOtherPermits->value,
            'for_inspection' => ApplicationStatus::AwaitingOtherPermits->value,
        ];
        $moved = [];
        foreach ($statusMap as $from => $to) {
            $n = DB::table('applications')->where('status', $from)->update(['status' => $to]);
            if ($n > 0) {
                $moved[$from.' → '.$to] = $n;
            }
        }

        /*
         * The history table keeps the OLD names on purpose. Those rows are a
         * record of what the system said at the time, and rewriting them would
         * destroy the only evidence of how a filing actually moved — the
         * timeline would claim an application entered `awaiting_other_permits`
         * on a date when no such status existed. Readers of
         * `application_status_history` must tolerate retired values; nothing
         * dereferences them into the enum.
         */

        $after = [
            'applications' => DB::table('applications')->count(),
            'pivot' => DB::table('application_permit_types')->count(),
            'permits' => DB::table('permits')->count(),
        ];

        foreach (['applications', 'pivot', 'permits'] as $t) {
            if ($before[$t] !== $after[$t]) {
                throw new RuntimeException(
                    "Row count changed for {$t}: {$before[$t]} → {$after[$t]}. This migration adds columns and rewrites values; it must never add or remove a row."
                );
            }
        }

        echo PHP_EOL.'  rows unchanged: applications='.$after['applications']
            .' pivot='.$after['pivot'].' permits='.$after['permits'].PHP_EOL;
        echo '  pivot set to approved: '.$issued->count().PHP_EOL;
        foreach ($moved as $label => $n) {
            echo '  applications '.$label.': '.$n.PHP_EOL;
        }
    }

    /**
     * Reversible in shape, not in history. The dropped columns take the
     * per-permit progress with them, and the application statuses go back to
     * the closest old name — `awaiting_other_permits` cannot know whether it
     * was `under_review` or `for_inspection` before, so it becomes
     * `under_review`. Rolling forward again is lossy in the same place.
     */
    public function down(): void
    {
        DB::table('applications')
            ->where('status', ApplicationStatus::ForApproval->value)
            ->update(['status' => 'submitted']);
        DB::table('applications')
            ->whereIn('status', [
                ApplicationStatus::AwaitingOtherPermits->value,
                ApplicationStatus::ForFinalApproval->value,
            ])
            ->update(['status' => 'under_review']);

        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->dropIndex(['permit_type_id', 'status']);
            $table->dropColumn([
                'status', 'mode', 'submitted_at', 'decided_at', 'remarks', 'rejection_reason',
            ]);
        });
    }
};
