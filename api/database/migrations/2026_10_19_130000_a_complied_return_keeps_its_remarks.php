<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every return, kept after it is answered (client, 19 October 2026).
 *
 * *"When a returned application was complied, the remark should not be
 * removed."* The live columns — `remarks`, `remarks_target` and
 * `returned_values` on the filing's assignments, the same pair plus
 * `returned_state` on each `application_permit_types` row, and the
 * `application_return_notes` set — describe ONE open round and are cleared
 * or replaced when the owner answers it, because the screens read them as
 * "what to fix now". After that only the audit log and the status history
 * remembered that anything had been asked. The client chose "history on both
 * sides": the owner and the office both still see what was asked, when, and
 * what the owner changed in answer.
 *
 * So this table is ADDITIVE. Nothing that wipes a live column was changed;
 * `App\Support\ReturnHistory` writes a row here on every return and refusal
 * (`WorkflowService::returnMainForm`, `returnClearance`, `rejectClearance`),
 * rewrites it in place on an amend, and closes it from the two status writers
 * (`transition`, `transitionClearance`) — complied on the move back to For
 * Approval, superseded when the filing is rejected or cancelled while the
 * question is still open.
 *
 * ── Columns ─────────────────────────────────────────────────────────────────
 *
 *  - `permit_type_id` null = the business permit's main form (BPLO's return);
 *    set = that clearance's sheet.
 *  - `department_id` is the office whose SHEET this is. It is what
 *    `ApplicationVisibility::readsOfficeSheet` is asked about, the same
 *    boundary the live `remarks` columns sit behind. `returned_by_department_id`
 *    is who pressed Return — they differ when BPLO sends back a clearance's
 *    uploaded certificate at Final Approval.
 *  - `items`: one entry per target the return named, with the officer's note
 *    and what the target said at the moment of return. `changes`: the same
 *    targets at compliance, old → new.
 *  - `source`: `recorded` when written by the workflow, `backfill` when
 *    reconstructed below.
 *
 * ── Backfill: what could be recovered ───────────────────────────────────────
 *
 * The audit log is NOT the source. It holds `application.corrected` (targets
 * only, no remarks) and status changes without wording — measured on the live
 * register on 19 October 2026: 5, 1 and 2 rows of the three correction
 * actions, no return action at all. `application_status_history` is better:
 * every return and every refusal wrote a row whose `note` IS the remark, with
 * who and when, and the next move out of that state is the compliance. So:
 *
 *  1. one row per status-history move INTO `returned` (main form or
 *     clearance) or a clearance's `rejected`; the filing's own `rejected` is a
 *     decision, not a return, and is skipped;
 *  2. complied by the next move of the same scope out of that state to
 *     `for_approval`; superseded if it left any other way or the filing has
 *     ended; open otherwise;
 *  3. items from `application_return_notes` written inside that round — the
 *     set is replaced each round, so only the LATEST round of each scope still
 *     has notes — plus the live pointer for that latest round where it survived
 *     (BPLO's assignment keeps `remarks_target` after resubmission; a
 *     clearance's is cleared, so only an open one has it);
 *  4. changes from `application_corrections` written inside the round's window.
 *     For a clearance these rows were rarely written (the snapshot they diff was
 *     only captured on a refusal or an amend), so most backfilled clearance
 *     rounds show the remark and notes and no before/after.
 *
 * Earlier rounds keep their remark, officer and dates; their per-field notes
 * were deleted at the time and cannot be recovered from anything.
 *
 * Finally, any return open on the live columns with no history row (seeded or
 * written straight to the table) gets an `open` row from those columns.
 *
 * To undo: `down()` drops the table. Nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permit_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('return');
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->text('items')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('amended_at')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('complied_at')->nullable();
            $table->foreignId('complied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('changes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('source', 16)->default('recorded');
            $table->timestamps();

            $table->index(['application_id', 'permit_type_id', 'status']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('application_returns');
    }

    /** Public so a test can run it against a fixture register. */
    public function backfill(): void
    {
        $bplo = DB::table('permit_types')->where('code', 'BUSINESS')->value('issuing_department_id');
        $issuing = DB::table('permit_types')->pluck('issuing_department_id', 'id');
        $permitNames = DB::table('permit_types')->pluck('name', 'code');
        $docNames = DB::table('document_types')->pluck('name', 'code');
        $userDept = DB::table('users')->pluck('department_id', 'id');
        $ended = ['rejected', 'cancelled'];

        $label = fn (string $code) => $docNames[$code] ?? $permitNames[$code] ?? null;

        $moves = DB::table('application_status_history')
            ->orderBy('id')
            ->get(['id', 'application_id', 'permit_type_id', 'from_status', 'to_status', 'changed_by_user_id', 'department_id', 'note', 'created_at']);

        /* Each scope's moves in order: application id + permit type (0 = main form). */
        $byScope = $moves->groupBy(fn ($m) => $m->application_id.':'.($m->permit_type_id ?? 0));
        $appStatus = DB::table('applications')->pluck('status', 'id');

        $rows = [];
        foreach ($byScope as $scope) {
            $scope = $scope->values();
            $returnIdx = $scope->keys()->filter(function ($i) use ($scope) {
                $m = $scope[$i];

                return $m->to_status === 'returned'
                    || ($m->to_status === 'rejected' && $m->permit_type_id !== null);
            })->values();

            foreach ($returnIdx as $n => $i) {
                $m = $scope[$i];
                $isLatest = $n === $returnIdx->count() - 1;

                $exit = $scope->slice($i + 1)->first(fn ($x) => $x->from_status === $m->to_status);
                $status = 'open';
                $compliedAt = null;
                $compliedBy = null;
                $closedAt = null;
                if ($exit !== null && $exit->to_status === 'for_approval') {
                    $status = 'complied';
                    $compliedAt = $exit->created_at;
                    $compliedBy = $exit->changed_by_user_id;
                } elseif ($exit !== null) {
                    $status = 'superseded';
                    $closedAt = $exit->created_at;
                } elseif (in_array($appStatus[$m->application_id] ?? null, $ended, true)) {
                    $status = 'superseded';
                }

                $notes = DB::table('application_return_notes')
                    ->where('application_id', $m->application_id)
                    ->when(
                        $m->permit_type_id === null,
                        fn ($q) => $q->whereNull('permit_type_id'),
                        fn ($q) => $q->where('permit_type_id', $m->permit_type_id),
                    )
                    ->where('created_at', '>=', $this->shift($m->created_at, -60))
                    ->when($compliedAt !== null, fn ($q) => $q->where('created_at', '<=', $compliedAt))
                    ->pluck('note', 'target')
                    ->all();

                $codes = array_keys($notes);
                if ($isLatest) {
                    $pointer = $m->permit_type_id === null
                        ? DB::table('application_assignments')
                            ->where('application_id', $m->application_id)
                            ->where('department_id', $bplo)
                            ->value('remarks_target')
                        : ($status === 'open'
                            ? DB::table('application_permit_types')
                                ->where('application_id', $m->application_id)
                                ->where('permit_type_id', $m->permit_type_id)
                                ->value('remarks_target')
                            : null);
                    foreach (explode(',', (string) $pointer) as $code) {
                        $code = trim($code);
                        if ($code !== '' && ! in_array($code, $codes, true)) {
                            $codes[] = $code;
                        }
                    }
                }

                $corrections = $compliedAt === null
                    ? collect()
                    : DB::table('application_corrections')
                        ->where('application_id', $m->application_id)
                        ->when(
                            $m->permit_type_id === null,
                            fn ($q) => $q->whereNull('permit_type_id'),
                            fn ($q) => $q->where('permit_type_id', $m->permit_type_id),
                        )
                        ->where('created_at', '>=', $m->created_at)
                        ->where('created_at', '<=', $this->shift($compliedAt, 5))
                        ->orderBy('id')
                        ->get()
                        ->keyBy('target');

                foreach ($corrections->keys() as $code) {
                    if (! in_array($code, $codes, true)) {
                        $codes[] = $code;
                    }
                }

                $items = array_map(fn (string $code) => [
                    'code' => $code,
                    'label' => $label($code),
                    'kind' => isset($docNames[$code]) ? 'document' : (str_starts_with($code, 'form:') ? 'field' : 'other'),
                    'note' => $notes[$code] ?? null,
                    'value' => $corrections[$code]->old_value ?? null,
                    'document_ids' => null,
                    'comparable' => isset($corrections[$code]),
                ], $codes);

                $changes = $status !== 'complied' ? null : $corrections->map(fn ($c) => [
                    'code' => $c->target,
                    'label' => $label($c->target),
                    'kind' => isset($docNames[$c->target]) ? 'document' : 'field',
                    'old' => $c->old_value,
                    'new' => $c->new_value,
                    'changed' => $c->old_value !== $c->new_value,
                    'replaced' => [],
                    'comparable' => true,
                ])->values()->all();

                $rows[] = [
                    'application_id' => $m->application_id,
                    'permit_type_id' => $m->permit_type_id,
                    'department_id' => $m->permit_type_id === null
                        ? $bplo
                        : ($issuing[$m->permit_type_id] ?? $m->department_id),
                    'kind' => $m->to_status === 'rejected' ? 'refusal' : 'return',
                    'returned_by_user_id' => $m->changed_by_user_id,
                    'returned_by_department_id' => $m->changed_by_user_id !== null
                        ? ($userDept[$m->changed_by_user_id] ?? null)
                        : null,
                    'remarks' => $m->note,
                    'items' => json_encode($items),
                    'returned_at' => $m->created_at,
                    'status' => $status,
                    'complied_at' => $compliedAt,
                    'complied_by_user_id' => $compliedBy,
                    'changes' => $changes === null ? null : json_encode($changes),
                    'closed_at' => $closedAt,
                    'source' => 'backfill',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('application_returns')->insert($chunk);
        }

        $this->backfillLiveOpenReturns($bplo, $issuing, $label);
    }

    /**
     * A return standing on the live columns with no history behind it.
     *
     * @param  Collection<int, int|null>  $issuing
     */
    private function backfillLiveOpenReturns(?int $bplo, $issuing, callable $label): void
    {
        $open = fn (int $appId, ?int $permitTypeId) => DB::table('application_returns')
            ->where('application_id', $appId)
            ->where('status', 'open')
            ->when(
                $permitTypeId === null,
                fn ($q) => $q->whereNull('permit_type_id'),
                fn ($q) => $q->where('permit_type_id', $permitTypeId),
            )
            ->exists();

        $items = function (int $appId, ?int $permitTypeId, ?string $pointer) use ($label) {
            $notes = DB::table('application_return_notes')
                ->where('application_id', $appId)
                ->when(
                    $permitTypeId === null,
                    fn ($q) => $q->whereNull('permit_type_id'),
                    fn ($q) => $q->where('permit_type_id', $permitTypeId),
                )
                ->pluck('note', 'target')
                ->all();
            $codes = array_keys($notes);
            foreach (explode(',', (string) $pointer) as $code) {
                $code = trim($code);
                if ($code !== '' && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }

            return json_encode(array_map(fn ($code) => [
                'code' => $code,
                'label' => $label($code),
                'kind' => str_starts_with($code, 'form:') ? 'field' : 'other',
                'note' => $notes[$code] ?? null,
                'value' => null,
                'document_ids' => null,
                'comparable' => false,
            ], $codes));
        };

        $clearances = DB::table('application_permit_types')
            ->whereIn('status', ['returned', 'rejected'])
            ->get();
        foreach ($clearances as $row) {
            if ($open($row->application_id, $row->permit_type_id)) {
                continue;
            }
            DB::table('application_returns')->insert([
                'application_id' => $row->application_id,
                'permit_type_id' => $row->permit_type_id,
                'department_id' => $issuing[$row->permit_type_id] ?? null,
                'kind' => $row->status === 'rejected' ? 'refusal' : 'return',
                'remarks' => $row->remarks,
                'items' => $items($row->application_id, $row->permit_type_id, $row->remarks_target),
                'returned_at' => $row->returned_at ?? $row->rejected_at ?? $row->updated_at,
                'status' => 'open',
                'source' => 'backfill',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $filings = DB::table('applications')->where('status', 'returned')->pluck('id');
        foreach ($filings as $appId) {
            if ($open($appId, null)) {
                continue;
            }
            $assignment = DB::table('application_assignments')
                ->where('application_id', $appId)
                ->where('department_id', $bplo)
                ->first();
            DB::table('application_returns')->insert([
                'application_id' => $appId,
                'permit_type_id' => null,
                'department_id' => $bplo,
                'kind' => 'return',
                'remarks' => $assignment->remarks ?? null,
                'items' => $items($appId, null, $assignment->remarks_target ?? null),
                'returned_at' => $assignment->updated_at ?? null,
                'status' => 'open',
                'source' => 'backfill',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** A stored timestamp moved by some seconds, in the column's own format. */
    private function shift(?string $at, int $seconds): ?string
    {
        if ($at === null) {
            return null;
        }

        return Carbon::parse($at)->addSeconds($seconds)->format('Y-m-d H:i:s');
    }
};
