<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BPLO's return gets the pointer every other return already has.
 *
 * ── The gap ──────────────────────────────────────────────────────────────────
 *
 * `application_permit_types.remarks_target` has existed since the office sheets
 * were built: an office returns one permit, names the checklist row or the
 * answer it means, and `OfficeFormStep` flags that row for the applicant. The
 * prose is never parsed to derive it.
 *
 * BPLO returning the MAIN FORM had no such column. `returnMainForm` took the
 * remarks and nothing else, so an officer who wanted the applicant to fix the
 * trade name could only say so in a sentence and hope they found it — on a form
 * with fifty-odd questions across five sections. Client, 24 September 2026:
 * *"allow me to choose a field that the business owner will have to comply to.
 * Then, I should also put a reason why."*
 *
 * ── Why it lands on the ASSIGNMENT ───────────────────────────────────────────
 *
 * Because that is where the remarks it belongs to already live. `returnMainForm`
 * writes `status` and `remarks` on BPLO's own assignment row and moves the
 * filing to Returned; the pointer is the other half of that same sentence, and
 * splitting the two across tables would let one be written without the other.
 *
 * Not on `applications`: a filing can be returned by BPLO more than once and by
 * different seats over its life, and the assignment row is what says WHO said
 * it and WHEN. A column on the filing would hold one anonymous target and
 * overwrite the history the assignment already keeps.
 *
 * 120 characters, matching `application_permit_types.remarks_target` — these
 * are codes the system owns, not prose, and the two columns answer the same
 * question about different rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_assignments', function (Blueprint $table) {
            $table->string('remarks_target', 120)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('application_assignments', function (Blueprint $table) {
            $table->dropColumn('remarks_target');
        });
    }
};
