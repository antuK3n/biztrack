<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section B items 7 and 8 both say "Yes (Please attach a copy of your …)".
 *
 * Neither copy had anywhere to go. There was no tax-incentive certificate
 * document type at all, so the applicant was told to put it under **Other
 * Requirements** — a bin, not a requirement, which means no office can tell a
 * missing certificate from a filing that never needed one. And the lease was
 * folded into `LEASE_TITLE`, "Lease Contract or Land Title", demanded of every
 * filing: one label, two documents, and the half that applied decided by an
 * answer the requirement could not see.
 *
 * This carries the seeder's three new types and their gating to a database that
 * was seeded before them. `ReferenceSeeder` is idempotent and says the same
 * thing, but per the working agreement a live register is not re-seeded.
 *
 * ── The gating is a new KIND of context ───────────────────────────────────
 *
 * `permit_type_requirements.context` held 'all' or an application type, both
 * facts about the FILING. 'tax_incentives', 'rented' and 'owned' are facts
 * about the applicant's ANSWERS on it. Nothing that reads the column has to
 * change — an unrecognised context is already treated as not-applicable by
 * `ApplyWizard::requiredDocs` — so an older client simply does not show the
 * three new rows rather than showing them wrongly.
 *
 * `LEASE_TITLE` is DETACHED from the business permit, not deleted. Filings
 * already carry attachments under that type and dropping it would orphan them
 * from their own name in every officer's document list; it is only no longer
 * demanded of a new filing.
 */
return new class extends Migration
{
    private const TYPES = [
        ['TAX_INCENTIVE_CERT', 'Tax Incentive Certificate', 'The certificate from the government entity granting your tax incentive. Asked because you answered Yes to item 7.'],
        ['LEASE_CONTRACT', 'Contract of Lease', 'Your lease over the premises. Asked because you answered Yes to item 8.'],
        ['LAND_TITLE', 'Land Title or Tax Declaration', 'Proof you own the premises, since you are not paying rent for them.'],
    ];

    private const GATES = [
        'TAX_INCENTIVE_CERT' => ['tax_incentives', 'Required when item 7 is Yes.'],
        'LEASE_CONTRACT' => ['rented', 'Required when item 8 is Yes.'],
        'LAND_TITLE' => ['owned', 'Required when item 8 is No.'],
    ];

    public function up(): void
    {
        $typesBefore = DB::table('document_types')->count();
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');

        if ($business === null) {
            echo "  no BUSINESS permit type — nothing to attach to\n";

            return;
        }

        $pivotBefore = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)->count();

        foreach (self::TYPES as [$code, $name, $help]) {
            // updateOrInsert, so re-running cannot duplicate a code that is
            // unique anyway, and a row the seeder already made is left alone.
            DB::table('document_types')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'help_text' => $help, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        foreach (self::GATES as $code => [$context, $notes]) {
            $typeId = DB::table('document_types')->where('code', $code)->value('id');
            if ($typeId === null) {
                continue;
            }

            DB::table('permit_type_requirements')->updateOrInsert(
                ['permit_type_id' => $business, 'document_type_id' => $typeId],
                ['context' => $context, 'is_mandatory' => true, 'notes' => $notes, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // Detach the combined requirement. The TYPE survives; only the demand
        // on a new business-permit filing goes.
        $leaseTitle = DB::table('document_types')->where('code', 'LEASE_TITLE')->value('id');
        $detached = $leaseTitle === null ? 0 : DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->where('document_type_id', $leaseTitle)
            ->delete();

        $typesAfter = DB::table('document_types')->count();
        $pivotAfter = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)->count();

        echo sprintf(
            "  document_types %d -> %d; business-permit requirements %d -> %d; LEASE_TITLE rows detached: %d\n",
            $typesBefore,
            $typesAfter,
            $pivotBefore,
            $pivotAfter,
            $detached,
        );
    }

    public function down(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            return;
        }

        $ids = DB::table('document_types')
            ->whereIn('code', array_keys(self::GATES))
            ->pluck('id');

        DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->whereIn('document_type_id', $ids)
            ->delete();

        // Put the combined requirement back, so a rollback leaves a filing
        // still being asked for proof of tenure rather than for nothing.
        $leaseTitle = DB::table('document_types')->where('code', 'LEASE_TITLE')->value('id');
        if ($leaseTitle !== null) {
            DB::table('permit_type_requirements')->updateOrInsert(
                ['permit_type_id' => $business, 'document_type_id' => $leaseTitle],
                ['context' => 'all', 'is_mandatory' => true, 'notes' => null, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        /*
         * The three document TYPES are left in place. An applicant may have
         * uploaded against them by now, and deleting the type would orphan the
         * attachment from its name — the same reasoning that keeps LEASE_TITLE.
         */
    }
};
