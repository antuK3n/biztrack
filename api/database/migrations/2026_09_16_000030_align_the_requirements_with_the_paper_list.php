<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MCG-BPLO-FO-001's DOCUMENTARY REQUIREMENTS, as printed.
 *
 * Six items, and this settles `docs/questions-for-malabon.md` §E9 — open since
 * August, which asked BPLO to confirm the list because BizTrack was demanding
 * seven things nobody had checked. The standing decision there was *"we removed
 * nothing from the list, because deleting a requirement on a guess is the one
 * mistake here that reaches the counter"*. It is no longer a guess: the client
 * supplied the page on 16 September 2026.
 *
 *   1  Proof of Business Registration (DTI / SEC / CDA by structure)
 *   2  Locational Clearance
 *   3  Contract of Lease AND Business Permit of Lessor (if leased),
 *      or Tax Declaration / Transfer Certificate of Title (if owned)
 *   4  Occupancy Permit (if required)
 *   5  Sketch and photos of location of business
 *   6  Special power of attorney / Authorization to transact for a
 *      representative, together with photocopies of IDs
 *
 * ── What this changes, and the one risk in it ─────────────────────────────
 *
 * DROPPED, because the printed list does not contain them: Barangay Business
 * Clearance and the Community Tax Certificate (Cedula).
 *
 * NARROWED: Valid Government ID. The paper asks for IDs once — in item 6,
 * attached to the representative's authorisation — not of every applicant. So
 * its context moves from 'all' to 'representative'.
 *
 * That narrowing is the risk and it is worth stating plainly: a sole proprietor
 * filing in person now uploads no identification at all. The client chose this
 * having seen it, over keeping the list as it was. If the counter turns such an
 * applicant away, the fix is one line — context back to 'all' — and the
 * document type is untouched either way.
 *
 * ADDED: the lessor's business permit (item 3's second half), the sketch and
 * photos (item 5), and the SPA (item 6). Item 2 is deliberately NOT added:
 * BizTrack issues the Locational Clearance itself in the LGU Clearances stage,
 * so demanding it up front would ask a new business for the output of a stage
 * it has not reached. Recorded as a divergence rather than left implicit.
 *
 * Nothing is DELETED from `document_types` anywhere in this migration. Filings
 * already carry attachments under BRGY_CLEARANCE and CEDULA, and dropping the
 * type would orphan them from their own name in every officer's document list.
 * Only the demand on a new filing goes.
 */
return new class extends Migration
{
    private const TYPES = [
        ['LESSOR_PERMIT', 'Business Permit of Lessor', 'The lessor&rsquo;s own business permit, which the paper asks for alongside your lease.'],
        ['LOCATION_SKETCH', 'Sketch and Photos of Location', 'A sketch of how to reach the premises, with photos of the place of business.'],
        ['SPA_AUTHORIZATION', 'SPA / Authorization to Transact', 'A special power of attorney or authorisation letter for the person filing on your behalf, with photocopies of their ID.'],
    ];

    /** code => [context, mandatory, notes] */
    private const GATES = [
        'LESSOR_PERMIT' => ['rented', true, 'Paper item 3 — asked with the Contract of Lease.'],
        'LOCATION_SKETCH' => ['all', true, 'Paper item 5.'],
        'SPA_AUTHORIZATION' => ['representative', true, 'Paper item 6 — only when somebody files on the owner&rsquo;s behalf.'],
    ];

    private const DROPPED = ['BRGY_CLEARANCE', 'CEDULA'];

    public function up(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            echo "  no BUSINESS permit type — nothing to align\n";

            return;
        }

        $before = DB::table('permit_type_requirements')->where('permit_type_id', $business)->count();

        foreach (self::TYPES as [$code, $name, $help]) {
            DB::table('document_types')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'help_text' => $help, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        foreach (self::GATES as $code => [$context, $mandatory, $notes]) {
            $typeId = DB::table('document_types')->where('code', $code)->value('id');
            if ($typeId === null) {
                continue;
            }
            DB::table('permit_type_requirements')->updateOrInsert(
                ['permit_type_id' => $business, 'document_type_id' => $typeId],
                ['context' => $context, 'is_mandatory' => $mandatory, 'notes' => $notes, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // Item 6 ties IDs to the representative, not to every applicant.
        $validId = DB::table('document_types')->where('code', 'VALID_ID')->value('id');
        $narrowed = $validId === null ? 0 : DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->where('document_type_id', $validId)
            ->update(['context' => 'representative', 'notes' => 'Paper item 6 — the representative&rsquo;s ID, with their authorisation.']);

        $droppedIds = DB::table('document_types')->whereIn('code', self::DROPPED)->pluck('id');
        $dropped = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->whereIn('document_type_id', $droppedIds)
            ->delete();

        $after = DB::table('permit_type_requirements')->where('permit_type_id', $business)->count();

        echo sprintf(
            "  business-permit requirements %d -> %d; added %d; detached %d; VALID_ID rows narrowed %d\n",
            $before,
            $after,
            count(self::GATES),
            $dropped,
            $narrowed,
        );
    }

    public function down(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            return;
        }

        DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->whereIn('document_type_id', DB::table('document_types')->whereIn('code', array_keys(self::GATES))->pluck('id'))
            ->delete();

        // Put the two back, and widen IDs again, so a rollback restores the
        // list the counter was being shown before this ran.
        foreach (self::DROPPED as $code) {
            $id = DB::table('document_types')->where('code', $code)->value('id');
            if ($id !== null) {
                DB::table('permit_type_requirements')->updateOrInsert(
                    ['permit_type_id' => $business, 'document_type_id' => $id],
                    ['context' => 'all', 'is_mandatory' => true, 'notes' => null, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        $validId = DB::table('document_types')->where('code', 'VALID_ID')->value('id');
        if ($validId !== null) {
            DB::table('permit_type_requirements')
                ->where('permit_type_id', $business)
                ->where('document_type_id', $validId)
                ->update(['context' => 'all', 'notes' => null]);
        }
    }
};
