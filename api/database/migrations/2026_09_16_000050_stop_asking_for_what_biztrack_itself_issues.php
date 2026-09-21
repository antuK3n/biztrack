<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two rows off the business permit's documentary requirements.
 *
 * ── The Occupancy Permit ──────────────────────────────────────────────────
 *
 * It is on the paper (item 4, "Occupancy Permit (if required)") and it was kept
 * here, optional, with this reasoning in the seeder: *"requiring the certificate
 * up front asks the applicant to produce the output of a stage they have not
 * reached. It still appears in the list, marked optional, for the applicant who
 * already holds one."*
 *
 * The first half is right and is exactly why the Locational Clearance — paper
 * item 2 — was never added. The second half stopped being true. `HeldPermits`
 * mints a HELD_<CODE> slot on demand for every clearance, so an applicant who
 * already holds an Occupancy Permit uploads it in the LGU Clearances stage
 * through "Upload an existing copy", which also spares them the office form and
 * the inspection. The optional row here is a second, worse route to the same
 * place: it takes the file but leaves the clearance un-applied-for.
 *
 * The client, 16 September 2026: *"I know it is in the paper forms, but the
 * user will just apply for it, so I think it should be removed too."* Consistent
 * with item 2, and the divergence from the paper is recorded in the seeder.
 *
 * ── The Valid Government ID ───────────────────────────────────────────────
 *
 * This is a correction to my own split. The paper's item 6 is ONE line:
 *
 *   "Special power of attorney (SPA)/Authorization to transact for
 *    representative together with photocopies of IDs"
 *
 * One requirement, two documents, handed in together. It was seeded as two
 * rows — SPA_AUTHORIZATION and VALID_ID — so the list asked twice for one item,
 * and once the representative gate was removed VALID_ID showed to every
 * applicant as an optional "Valid Government ID" the paper never asks of an
 * owner filing in person.
 *
 * So VALID_ID is detached and the IDs go into the SPA row's own name and help
 * text, which is how the paper prints it. The document TYPE stays: filings
 * already carry attachments under it, and the sanitary and fire checklists still
 * ask for it in their own right.
 */
return new class extends Migration
{
    private const DETACH = ['OCCUPANCY', 'VALID_ID'];

    public function up(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            echo "  no BUSINESS permit type — nothing to change\n";

            return;
        }

        $before = DB::table('permit_type_requirements')->where('permit_type_id', $business)->count();

        $detached = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->whereIn(
                'document_type_id',
                DB::table('document_types')->whereIn('code', self::DETACH)->pluck('id'),
            )
            ->delete();

        // Item 6 as the paper prints it: the authorisation and the IDs together.
        $spa = DB::table('document_types')->where('code', 'SPA_AUTHORIZATION')->value('id');
        if ($spa !== null) {
            DB::table('document_types')->where('id', $spa)->update([
                'name' => 'SPA / Authorization to Transact, with ID photocopies',
                'help_text' => 'A special power of attorney or authorisation letter for the person filing on your behalf, together with photocopies of their ID. Only needed if somebody is transacting for you.',
                'updated_at' => now(),
            ]);
        }

        $after = DB::table('permit_type_requirements')->where('permit_type_id', $business)->count();

        echo sprintf(
            "  business-permit requirements %d -> %d; detached %d (%s)\n",
            $before,
            $after,
            $detached,
            implode(', ', self::DETACH),
        );
    }

    public function down(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            return;
        }

        // Put both back as they were: optional, unconditional.
        foreach (self::DETACH as $code) {
            $id = DB::table('document_types')->where('code', $code)->value('id');
            if ($id === null) {
                continue;
            }
            DB::table('permit_type_requirements')->updateOrInsert(
                ['permit_type_id' => $business, 'document_type_id' => $id],
                ['context' => 'all', 'is_mandatory' => false, 'notes' => null, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $spa = DB::table('document_types')->where('code', 'SPA_AUTHORIZATION')->value('id');
        if ($spa !== null) {
            DB::table('document_types')->where('id', $spa)->update([
                'name' => 'SPA / Authorization to Transact',
                'updated_at' => now(),
            ]);
        }
    }
};
