<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The amendment form's own requirements, from the LGU's own paper.
 *
 * ── What an amendment was being asked for ──────────────────────────────────
 *
 * The new application's list. Measured 19 September 2026: a filing of type
 * `amendment` was shown Proof of Business Registration and the Sketch and
 * photos of location — both mandatory, both on context `all` — plus the lease
 * or the land title depending on whether the business rents. So somebody
 * correcting a floor area was required to attach a DTI registration and a
 * sketch map of premises that had not moved.
 *
 * MCG-BPLO-FO-003 prints a requirement list PER GROUP, and they are not the
 * new application's:
 *
 *   every amendment   affidavit requesting BPLO to acknowledge it;
 *                     SPA/authorisation if a representative files
 *   change of address contract of lease OR proof of ownership;
 *                     picture and sketch map of the location
 *   change of trade   DTI registration (sole proprietor)
 *   corporations      amended Articles of Incorporation, Board Resolution
 *                     and Secretary's Certification
 *
 * ── Two things on the paper that are deliberately NOT asked for ───────────
 *
 *  - **Photocopy of the Business/Mayor's Permit.** BizTrack issued it. Asking
 *    an applicant to upload a document this system printed is the thing the
 *    16 September migration "stop asking for what BizTrack itself issues" was
 *    written to end.
 *  - **Zoning clearance.** Also ours, and the copy the business holds names
 *    the OLD address, so it is the wrong document by definition. The client
 *    settled this on 19 September: CPDO is notified when a business moves and
 *    the judgement is theirs, rather than the applicant attaching a
 *    certificate that does not cover where they now are.
 *
 * ── Contexts become a LIST ────────────────────────────────────────────────
 *
 * `permit_type_requirements` holds one row per (permit type, document type),
 * so a document needed by two different situations could only name one of
 * them. The contract of lease is wanted by a renting NEW applicant and by
 * anybody changing address, and those are different questions.
 *
 * So `context` may now be comma-separated and the browser matches on any
 * token. Single values behave exactly as before, which is why nothing else in
 * the table has to change.
 */
return new class extends Migration
{
    /** code => [name, help text] */
    private const TYPES = [
        'AMEND_AFFIDAVIT' => [
            'Affidavit requesting the amendment',
            'A sworn statement asking BPLO to acknowledge this particular amendment. Required for every amendment.',
        ],
        'AMEND_CORP_DOCS' => [
            'Amended Articles of Incorporation, Board Resolution and Secretary’s Certification',
            'Corporations, partnerships and cooperatives only. A sole proprietor does not need these.',
        ],
    ];

    /** code => [context, mandatory] */
    private const GATES = [
        // Every amendment.
        'AMEND_AFFIDAVIT' => ['amendment', 1],
        // Corporations only, whatever is being amended.
        'AMEND_CORP_DOCS' => ['amend_corporate', 1],
        // A change of address: where you are now, and proof you may be there.
        'LEASE_CONTRACT' => ['rented,amend_address', 1],
        'LAND_TITLE' => ['owned,amend_address', 1],
        'LOCATION_SKETCH' => ['new,renewal,amend_address', 1],
        // A change of trade name: the registration that carries the new name.
        'DTI_SEC_CDA' => ['new,renewal,amend_trade_name', 1],
    ];

    public function up(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            return;
        }

        foreach (self::TYPES as $code => [$name, $help]) {
            DB::table('document_types')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'help_text' => $help,
                    'is_required' => 1,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $order = (int) DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->max('display_order');

        foreach (self::GATES as $code => [$context, $mandatory]) {
            $typeId = DB::table('document_types')->where('code', $code)->value('id');
            if ($typeId === null) {
                continue;
            }

            $existing = DB::table('permit_type_requirements')
                ->where('permit_type_id', $business)
                ->where('document_type_id', $typeId)
                ->first();

            DB::table('permit_type_requirements')->updateOrInsert(
                ['permit_type_id' => $business, 'document_type_id' => $typeId],
                [
                    'context' => $context,
                    'is_mandatory' => $mandatory,
                    // Keep an existing row's place in the list; only a new one
                    // goes on the end.
                    'display_order' => $existing->display_order ?? ($order += 10),
                    'updated_at' => now(),
                    'created_at' => $existing->created_at ?? now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');

        // Restore the two shared rows to the single context they carried.
        foreach (['LEASE_CONTRACT' => 'rented', 'LAND_TITLE' => 'owned', 'LOCATION_SKETCH' => 'all', 'DTI_SEC_CDA' => 'all'] as $code => $context) {
            $typeId = DB::table('document_types')->where('code', $code)->value('id');
            if ($typeId === null || $business === null) {
                continue;
            }
            DB::table('permit_type_requirements')
                ->where('permit_type_id', $business)
                ->where('document_type_id', $typeId)
                ->update(['context' => $context, 'updated_at' => now()]);
        }

        $ids = DB::table('document_types')->whereIn('code', array_keys(self::TYPES))->pluck('id');
        DB::table('permit_type_requirements')->whereIn('document_type_id', $ids)->delete();
        DB::table('document_types')->whereIn('id', $ids)->delete();
    }
};
