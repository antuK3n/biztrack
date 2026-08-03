<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checklist item 96 — stop the business permit demanding an Occupancy Permit
 * the applicant is about to be walked through obtaining.
 *
 * The complaint was about the COUNT ("should be 6"), and the count is not what
 * this changes: the wizard still lists six requirements for a new filing and
 * seven for a renewal, because nobody outside City Hall can say which six the
 * counter's form names and we do not have a copy of it
 * (`docs/questions-for-malabon.md` E1). Deleting a seeded requirement on that
 * evidence would be inventing the answer.
 *
 * What can be settled without the form is whether OCCUPANCY may BLOCK a filing,
 * and it may not, for two independent reasons:
 *
 *   1. Its own help text has always said "where applicable". A requirement that
 *      is conditional in its wording and mandatory in its data is simply
 *      contradicting itself, and the applicant sees the mandatory half.
 *   2. Since `docs/clearances-after-payment.md`, the Occupancy Permit is one of
 *      the six LGU clearances applied for in their own stage AFTER the first
 *      payment clears. Demanding it as an upload at step 4 asks for the output
 *      of a stage the applicant has not reached yet. For a new business in a
 *      new premises there is no such certificate to attach, so the requirement
 *      is unclearable — the same defect PRIOR_PERMIT was context-gated to avoid.
 *
 * So the row stays, the wording stays, the position in the list stays; only
 * `is_mandatory` moves. The requirement still appears, now marked "(optional)",
 * and an applicant who does hold an occupancy certificate can still attach it —
 * which is the outcome BPLO would want if the answer to E1 comes back "yes, we
 * do ask for it".
 *
 * Kept as a migration rather than only a seeder edit because
 * `permit_type_requirements` is live data in the tester's database and
 * `ReferenceSeeder` is not re-run against it.
 */
return new class extends Migration
{
    private const BUSINESS = 'BUSINESS';

    private const OCCUPANCY = 'OCCUPANCY';

    public function up(): void
    {
        $this->setMandatory(false);
    }

    /**
     * Reversible on purpose.
     *
     * Unlike a data normalisation, nothing here is lossy: the flag had one
     * value before and has the other now, and if the paper form turns out to
     * make the occupancy certificate a hard requirement of every business
     * permit, `migrate:rollback` puts it back exactly as it was.
     */
    public function down(): void
    {
        $this->setMandatory(true);
    }

    private function setMandatory(bool $mandatory): void
    {
        $permitTypeId = DB::table('permit_types')->where('code', self::BUSINESS)->value('id');
        $documentTypeId = DB::table('document_types')->where('code', self::OCCUPANCY)->value('id');

        // A database seeded before either code existed is not this migration's
        // to repair — it has no row to flip and nothing to say about it.
        if ($permitTypeId === null || $documentTypeId === null) {
            return;
        }

        DB::table('permit_type_requirements')
            ->where('permit_type_id', $permitTypeId)
            ->where('document_type_id', $documentTypeId)
            ->update(['is_mandatory' => $mandatory]);
    }
};
