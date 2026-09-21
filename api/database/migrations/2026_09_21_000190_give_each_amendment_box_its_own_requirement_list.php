<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MCG-BPLO-FO-003 prints a requirements list PER BOX. This makes the register
 * say the same.
 *
 * ── What was wrong ────────────────────────────────────────────────────────
 *
 * Client, 21 September 2026: *"the fields in paper and in system DOES NOT
 * REALLY MATCH… APPLY RULES WHERE NECESSARY. Also, only apply required in
 * fields only when necessary."* Measured against the paper, the rules were:
 *
 *  1. **Both tenure documents were asked of every move.** `LEASE_CONTRACT` is
 *     on context `rented,amend_address` and `LAND_TITLE` on
 *     `owned,amend_address`, and an amendment matches a context by token — so
 *     an address change demanded a contract of lease AND a land title, from
 *     everybody. The paper says "Contract of Lease and/or Proof of Ownership",
 *     and which one you have is decided by whether you rent.
 *  2. **DTI registration was asked of corporations.** `DTI_SEC_CDA` fires on
 *     `amend_trade_name` for anyone; the paper asks it "For Single Proprietor".
 *  3. **Corporate papers were mandatory on the unnumbered box**, where the
 *     paper says "(if required)".
 *  4. **Change of ownership had no rules at all**, because it had no fields.
 *
 * ── Two requirements the paper asks for and this deliberately does not ────
 *
 *  - **"Photocopy of Business/Mayor's Permit"**, on all four boxes. BizTrack
 *    issued that permit and holds it; asking an applicant to photograph a
 *    certificate this system printed is the counter's workaround for not
 *    having a register, reimplemented on top of the register.
 *  - **"Zoning clearance"**, on boxes I and the unnumbered one. A Malabon
 *    zoning clearance is a RECORD here, not an upload, and a cross-barangay
 *    move now applies for a fresh one as part of the amendment
 *    (`WorkflowService::permitTypeIdsAtSubmission`). Asking for a scan of the
 *    clearance the same filing is about to issue would be circular.
 *
 * Both are recorded here rather than dropped silently, so the next person to
 * hold the paper beside the screen can see the decision instead of a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        $businessPermit = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($businessPermit === null) {
            return;
        }

        /* ── The one document FO-003 asks for that BizTrack had no type for ── */
        $deed = DB::table('document_types')->where('code', 'AMEND_DEED_TRANSFER')->value('id');
        if ($deed === null) {
            $deed = DB::table('document_types')->insertGetId([
                'code' => 'AMEND_DEED_TRANSFER',
                'name' => 'Deed of Transfer',
                'help_text' => 'Deed of Sale or Assignment, Affidavit of Self-Adjudication, or '
                    .'Extra-Judicial Settlement of the estate of a deceased owner.',
                'is_required' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $id = fn (string $code) => DB::table('document_types')->where('code', $code)->value('id');

        /*
         * context, is_mandatory and display_order per (document, box).
         *
         * `amend_other` is the unnumbered box; `amend_address`, `amend_owner`
         * and `amend_trade_name` are I, II and III. `amend_corporate` and
         * `amend_sole` are the paper's two conditional lines ("For
         * Corporation…", "For Single Proprietor…") and are matched against the
         * business's registration type, not against a box.
         */
        $rules = [
            // Affidavit — every box asks for it, and it is the filing's spine.
            ['AMEND_AFFIDAVIT', 'amendment', true, 80],

            // I — the paper's "Contract of Lease AND/OR Proof of Ownership".
            ['LEASE_CONTRACT', 'rented,amend_address_rented', true, 50],
            ['LAND_TITLE', 'owned,amend_address_owned', true, 52],
            // I — "Picture and sketch map of location of establishment".
            ['LOCATION_SKETCH', 'new,renewal,amend_address', true, 20],

            // II — "Deed of Transfer".
            ['AMEND_DEED_TRANSFER', 'amend_owner', true, 85],

            /*
             * "For Single Proprietor – DTI Registration", on I, II and III.
             * Split from the new/renewal rows it shared so the amendment side
             * can carry the sole-proprietor condition the paper states and the
             * new-application side is left exactly as it was.
             */
            ['DTI_SEC_CDA', 'new,renewal,amend_sole', true, 10],

            /*
             * "For Corporation – Amended Articles of Incorporation, Board
             * Resolution and Secretary's Certification."
             *
             * Required on I, II and III; "(if required)" on the unnumbered
             * box. The pivot holds ONE row per (permit type, document type),
             * so those two strengths cannot both be stated here — and the
             * mandatory one is the one worth keeping, because the optional
             * case is already covered by "Other documents that may be
             * required" below. So `amend_corporate` now means "incorporated
             * AND amending address, ownership or trade name", which is where
             * the paper actually demands it; a corporation correcting its
             * floor area is no longer asked for amended Articles.
             */
            ['AMEND_CORP_DOCS', 'amend_corporate', true, 90],

            // "SPA/Authorization and ID's IF representative processes" — the
            // condition is the applicant's, so it is offered, never demanded.
            ['SPA_AUTHORIZATION', 'all', false, 70],

            // "Other documents that may be required" — by definition optional.
            ['OTHER', 'amendment', false, 99],
        ];

        foreach ($rules as [$code, $context, $mandatory, $order]) {
            $docId = $code === 'AMEND_DEED_TRANSFER' ? $deed : $id($code);
            if ($docId === null) {
                continue;
            }

            DB::table('permit_type_requirements')->updateOrInsert(
                ['permit_type_id' => $businessPermit, 'document_type_id' => $docId],
                [
                    'context' => $context,
                    'is_mandatory' => $mandatory,
                    'display_order' => $order,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $businessPermit = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($businessPermit === null) {
            return;
        }

        /*
         * The contexts as they stood before, restored by name. The Deed of
         * Transfer row goes; the document type itself stays, because dropping
         * a type an application may already reference would orphan uploads.
         */
        $previous = [
            ['DTI_SEC_CDA', 'new,renewal,amend_trade_name', true],
            ['LEASE_CONTRACT', 'rented,amend_address', true],
            ['LAND_TITLE', 'owned,amend_address', true],
            ['OTHER', null, false],
        ];

        foreach ($previous as [$code, $context, $mandatory]) {
            $docId = DB::table('document_types')->where('code', $code)->value('id');
            if ($docId === null) {
                continue;
            }

            DB::table('permit_type_requirements')
                ->where('permit_type_id', $businessPermit)
                ->where('document_type_id', $docId)
                ->update([
                    'context' => $context,
                    'is_mandatory' => $mandatory,
                    'updated_at' => now(),
                ]);
        }

        $deed = DB::table('document_types')->where('code', 'AMEND_DEED_TRANSFER')->value('id');
        if ($deed !== null) {
            DB::table('permit_type_requirements')
                ->where('permit_type_id', $businessPermit)
                ->where('document_type_id', $deed)
                ->delete();
        }
    }
};
