<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The documentary requirements, in the paper's order and in the paper's words.
 *
 * Three faults, found by holding the rendered list beside MCG-BPLO-FO-001's
 * DOCUMENTARY REQUIREMENTS block.
 *
 * ── 1. The order was incidental ────────────────────────────────────────────
 *
 * `PermitType::documentTypes()` carried no ordering clause at all, so the list
 * came out in whatever order the rows happened to be inserted. The Tax
 * Incentive Certificate — which is not on the paper's documentary list, it
 * comes from section B item 7 — sat second, between paper items 1 and 3.
 *
 * So the pivot gets a `display_order` and the relation orders by it. The
 * paper's items keep their printed positions, and the two rows BizTrack asks
 * for on its own account follow them, where they cannot be read as part of the
 * printed list:
 *
 *   10   1   Proof of Business Registration
 *   30   3   Contract of Lease                            (if rented)
 *   31   3   Business Permit of Lessor                    (if rented)
 *   32   3   Tax Declaration / Transfer Certificate       (if owned)
 *   50   5   Sketch and photos of location of business
 *   60   6   SPA / Authorization to Transact, with IDs
 *   70   –   Tax Incentive Certificate                    (section B item 7)
 *   80   –   Previous Mayor's Permit                      (renewals)
 *
 * The gaps are deliberate. 20 and 40 are where the paper's items 2 and 4 would
 * sit — the Locational Clearance and the Occupancy Permit, absent by decision
 * and not by oversight, because BizTrack issues both itself. Leaving their
 * slots empty means restoring either one is an insert and not a renumbering.
 *
 * Every other permit type's rows stay at the default, which sorts them by
 * document type id: exactly the order they come out in today.
 *
 * ── 2. An HTML entity was showing as text ──────────────────────────────────
 *
 * The lessor row's help text read "The lessor&rsquo;s own business permit".
 * React escapes the string it is handed, so the applicant read the entity.
 * The seeder's copy of the same line was wrong in a different way — "The
 * lessor own business permit", an apostrophe eaten by shell quoting on the way
 * in. Both get a real apostrophe.
 *
 * ── 3. The names did not echo the paper ────────────────────────────────────
 *
 * A clerk holding the screen against the printed checklist should be able to
 * read straight down one and find each line in the other. "Land Title or Tax
 * Declaration" becomes "Tax Declaration / Transfer Certificate of Title
 * (TCT)", which is how item 3 prints it; item 1 regains its "Proof of" and
 * item 5 its full phrase.
 *
 * The document TYPE names change, which is to say they change everywhere the
 * type appears. That is wanted: the chatbot recites these, and it should
 * recite what the counter's paper says. CPDD's own sheet is unaffected — it
 * carries its own labels in `ZoningRequirements::ROWS` and only points at
 * these types for the file.
 */
return new class extends Migration
{
    /** Paper position for each of the business permit's rows. */
    private const ORDER = [
        'DTI_SEC_CDA' => 10,
        'LEASE_CONTRACT' => 30,
        'LESSOR_PERMIT' => 31,
        'LAND_TITLE' => 32,
        'LOCATION_SKETCH' => 50,
        'SPA_AUTHORIZATION' => 60,
        'TAX_INCENTIVE_CERT' => 70,
        'PRIOR_PERMIT' => 80,
    ];

    /** [code, name, help_text] as the paper prints them. */
    private const WORDING = [
        ['DTI_SEC_CDA', 'Proof of Business Registration (DTI / SEC / CDA)', 'Your certificate of registration: DTI if you are a sole proprietor, SEC for a corporation, partnership or OPC, CDA for a cooperative.'],
        ['LAND_TITLE', 'Tax Declaration / Transfer Certificate of Title (TCT)', 'Proof you own the premises, since you are not paying rent for them. Either document will do.'],
        ['LESSOR_PERMIT', 'Business Permit of Lessor', "The lessor's own business permit, which the paper asks for alongside your lease."],
        ['LOCATION_SKETCH', 'Sketch and photos of location of business', 'A sketch of how to reach the premises, with photos of the place of business.'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('permit_type_requirements', 'display_order')) {
            Schema::table('permit_type_requirements', function (Blueprint $table) {
                $table->unsignedSmallInteger('display_order')->default(100);
            });
            echo "  added permit_type_requirements.display_order\n";
        }

        foreach (self::WORDING as [$code, $name, $help]) {
            DB::table('document_types')->where('code', $code)->update([
                'name' => $name,
                'help_text' => $help,
                'updated_at' => now(),
            ]);
        }
        echo sprintf("  document types reworded: %d\n", count(self::WORDING));

        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            echo "  no BUSINESS permit type — order left at the default\n";

            return;
        }

        $rows = DB::table('permit_type_requirements')->where('permit_type_id', $business)->count();
        $ordered = 0;
        foreach (self::ORDER as $code => $position) {
            $doc = DB::table('document_types')->where('code', $code)->value('id');
            if ($doc === null) {
                continue;
            }
            $ordered += DB::table('permit_type_requirements')
                ->where('permit_type_id', $business)
                ->where('document_type_id', $doc)
                ->update(['display_order' => $position, 'updated_at' => now()]);
        }

        echo sprintf("  business-permit requirements %d; ordered %d\n", $rows, $ordered);

        $unordered = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->where('display_order', 100)
            ->count();
        if ($unordered > 0) {
            echo sprintf("  WARNING: %d business row(s) have no paper position\n", $unordered);
        }
    }

    public function down(): void
    {
        foreach ([
            ['DTI_SEC_CDA', 'Business Registration (DTI / SEC / CDA)', 'Your DTI, SEC, or CDA certificate of registration.'],
            ['LAND_TITLE', 'Land Title or Tax Declaration', 'Proof you own the premises, since you are not paying rent for them.'],
            ['LESSOR_PERMIT', 'Business Permit of Lessor', 'The lessor own business permit, which the paper asks for alongside your lease.'],
            ['LOCATION_SKETCH', 'Sketch and Photos of Location', 'A sketch of how to reach the premises, with photos of the place of business.'],
        ] as [$code, $name, $help]) {
            DB::table('document_types')->where('code', $code)->update([
                'name' => $name,
                'help_text' => $help,
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasColumn('permit_type_requirements', 'display_order')) {
            Schema::table('permit_type_requirements', function (Blueprint $table) {
                $table->dropColumn('display_order');
            });
        }
    }
};
