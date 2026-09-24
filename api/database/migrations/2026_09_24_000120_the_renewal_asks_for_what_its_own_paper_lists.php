<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A renewal's documentary requirements become MCG-BPLO-FO-002's own list.
 *
 * ── What the paper asks for ──────────────────────────────────────────────────
 *
 * FO-002 prints four, and only four:
 *
 *   Submission of BIR Sales Tax Returns of the prior year or Quarterly VAT Sales
 *   Comprehensive General Liability Insurance (if applicable)
 *   Certification and clearance applicable for regulatory purposes ________
 *   Special power of attorney (SPA)/Authorization to transact for representative
 *     together with photocopies of IDs
 *
 * plus the two the form's own answers pull in: item 7 Yes attaches a tax
 * incentive certificate, item 8 Yes attaches a lease contract. Those two were
 * already wired, as the `tax_incentives` and `rented` contexts.
 *
 * ── What was being asked instead ─────────────────────────────────────────────
 *
 * A renewal was carrying three the paper does not list, all inherited from the
 * NEW application's list:
 *
 *   PRIOR_PERMIT     the previous Mayor's Permit — which BizTrack issued, holds
 *                    a row for, and links to this filing through
 *                    `prior_permit_id`. Asking the applicant to upload a scan of
 *                    a document the city produced is the clearest case of the
 *                    lot.
 *   DTI_SEC_CDA      proof of registration, unchanged since the new application
 *                    and already on the business record.
 *   LOCATION_SKETCH  a sketch and photos of premises that a renewal, by
 *                    definition, has not moved. One that HAS moved files an
 *                    amendment, and `amend_address` keeps this row for exactly
 *                    that.
 *
 * Client's decision, 24 September 2026, asked directly and answered "follow the
 * paper exactly".
 *
 * ── How the contexts change ──────────────────────────────────────────────────
 *
 * `context` is a comma-joined list of the situations a requirement applies to,
 * so this is a string edit and not a delete: `new,renewal,amend_address` becomes
 * `new,amend_address`, and the row keeps doing its job for the other two. Only
 * PRIOR_PERMIT is scoped to `renewal` alone, and it is UPDATED to a context
 * nothing matches rather than deleted — a row removed here would take its
 * display order and its notes with it, and this is a decision the client may
 * reverse when BPLO sees it.
 *
 * Nothing touches `application_documents`. Filings that already uploaded these
 * keep their uploads and their rows; this changes what is ASKED of filings from
 * here on.
 */
return new class extends Migration
{
    /**
     * The three the paper lists that BizTrack had no document type for.
     *
     * SPA_AUTHORIZATION is already on file with context `all`, so it reaches a
     * renewal without anything here.
     */
    private const ADDED = [
        [
            'code' => 'BIR_SALES_TAX_RETURN',
            'name' => 'BIR Sales Tax Returns (prior year) or Quarterly VAT Sales',
            'help' => 'The prior year’s returns, or your quarterly VAT sales if you file that way.',
            'order' => 10,
        ],
        [
            'code' => 'LIABILITY_INSURANCE',
            'name' => 'Comprehensive General Liability Insurance',
            'help' => 'If applicable to your line of business.',
            'order' => 20,
        ],
        [
            'code' => 'REGULATORY_CERTIFICATION',
            'name' => 'Certification and clearance applicable for regulatory purposes',
            'help' => 'Whatever your trade is separately regulated by, where one applies.',
            'order' => 30,
        ],
    ];

    public function up(): void
    {
        $businessId = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');

        if ($businessId === null) {
            return;
        }

        foreach (self::ADDED as $doc) {
            $typeId = DB::table('document_types')->where('code', $doc['code'])->value('id');

            if ($typeId === null) {
                $typeId = DB::table('document_types')->insertGetId([
                    'code' => $doc['code'],
                    'name' => $doc['name'],
                    'help_text' => $doc['help'],
                    /*
                     * Never required. Two of the three are conditional on the
                     * paper's own face — "(if applicable)" and "applicable for
                     * regulatory purposes" — and the BIR returns are a document
                     * a business may legitimately not have yet in January. BPLO
                     * asks for what is missing through Other Requirements,
                     * which is the mechanism for exactly this.
                     */
                    'is_required' => false,
                    'file_size_max_mb' => 10,
                    'accepted_formats' => 'pdf,jpg,jpeg,png',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $exists = DB::table('permit_type_requirements')
                ->where('permit_type_id', $businessId)
                ->where('document_type_id', $typeId)
                ->exists();

            if (! $exists) {
                DB::table('permit_type_requirements')->insert([
                    'permit_type_id' => $businessId,
                    'document_type_id' => $typeId,
                    'context' => 'renewal',
                    'is_mandatory' => false,
                    'display_order' => $doc['order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        /* The two shared rows keep every context except `renewal`. */
        foreach (
            [
                'LOCATION_SKETCH' => 'new,amend_address',
                'DTI_SEC_CDA' => 'new,amend_sole',
            ] as $code => $context
        ) {
            $typeId = DB::table('document_types')->where('code', $code)->value('id');

            if ($typeId !== null) {
                DB::table('permit_type_requirements')
                    ->where('permit_type_id', $businessId)
                    ->where('document_type_id', $typeId)
                    ->update(['context' => $context, 'updated_at' => now()]);
            }
        }

        /*
         * And the previous permit is parked rather than dropped. `renewal_off`
         * matches no context the resolver builds, so it asks for nothing — and
         * the row, its order and its history are all still here if BPLO wants
         * it back.
         */
        $priorId = DB::table('document_types')->where('code', 'PRIOR_PERMIT')->value('id');

        if ($priorId !== null) {
            DB::table('permit_type_requirements')
                ->where('permit_type_id', $businessId)
                ->where('document_type_id', $priorId)
                ->update(['context' => 'renewal_off', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $businessId = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');

        if ($businessId === null) {
            return;
        }

        foreach (
            [
                'LOCATION_SKETCH' => 'new,renewal,amend_address',
                'DTI_SEC_CDA' => 'new,renewal,amend_sole',
                'PRIOR_PERMIT' => 'renewal',
            ] as $code => $context
        ) {
            $typeId = DB::table('document_types')->where('code', $code)->value('id');

            if ($typeId !== null) {
                DB::table('permit_type_requirements')
                    ->where('permit_type_id', $businessId)
                    ->where('document_type_id', $typeId)
                    ->update(['context' => $context, 'updated_at' => now()]);
            }
        }

        /*
         * The three added rows go, and their document types with them only if
         * nothing has been uploaded against one. A type with uploads is left
         * alone: the filing that uploaded it still needs the row to name what
         * the file is.
         */
        foreach (self::ADDED as $doc) {
            $typeId = DB::table('document_types')->where('code', $doc['code'])->value('id');

            if ($typeId === null) {
                continue;
            }

            DB::table('permit_type_requirements')
                ->where('permit_type_id', $businessId)
                ->where('document_type_id', $typeId)
                ->delete();

            if (! DB::table('application_documents')->where('document_type_id', $typeId)->exists()) {
                DB::table('document_types')->where('id', $typeId)->delete();
            }
        }
    }
};
