<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `application_permit_types.rejection_reason` goes with the state it recorded.
 *
 * One permit could be refused outright, and the reason lived here. That act was
 * removed on 17 September 2026 — `ClearanceStatus::Rejected`,
 * `WorkflowService::rejectClearance()` and `refileClearance()`, none of which
 * had ever been reachable — after the client settled it: *"I think Return is
 * enough already."* The only method that ever wrote this column is gone, so
 * what is left is a column that can only ever be null.
 *
 * ── Dropped rather than left alone, and why that is the safer choice ──────
 *
 * A dead column is not harmless. It stays on the model's `$fillable`, in the
 * `withPivot` list, on a payload every office reads, and in the browser's
 * types — so the next reader has to work out whether it means anything, and the
 * next feature has somewhere plausible to put a value that nothing will ever
 * read back. That is the shape the rejection itself had: enough scaffolding to
 * look implemented.
 *
 * ── Safe to the row ───────────────────────────────────────────────────────
 *
 * Measured on the register before running: 19 pivot rows, 0 with a non-null
 * `rejection_reason`. Nothing is being destroyed. `down()` restores the column,
 * empty, which is exactly what it would restore it to anyway.
 *
 * NOT to be confused with `applications.rejection_reason`, which is BPLO
 * refusing a whole FILING. That is a live act, it is kept, and it is the right
 * answer for a business that genuinely cannot have the permit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable()->after('remarks');
        });
    }
};
