<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Everything certain first; everything that depends on an answer at the end.
 *
 * `2026_09_16_000060` put the business permit's requirements into the paper's
 * printed order, which fixed a Tax Incentive Certificate sitting at position 2.
 * It left a second fault in place, and this is it: the paper's sequence
 * interleaves rows an applicant must always upload with rows that appear only
 * because of how they answered section B.
 *
 *   Proof of Business Registration      always
 *   Contract of Lease                   only if item 8 is Yes
 *   Business Permit of Lessor           only if item 8 is Yes
 *   Tax Declaration / TCT               only if item 8 is No
 *   Sketch and photos of location       always
 *   SPA / Authorization                 always, optional
 *
 * On paper that order costs nothing — a clerk reads the whole printed list and
 * ticks what applies. On a screen it does cost something, because the screen
 * shows only the rows that apply, so the list an applicant actually sees is the
 * printed one with holes punched through the middle of it. The two things they
 * can go and fetch today, whatever else is true, were separated by up to three
 * rows that materialise and vanish with a radio button two steps back.
 *
 * The client, 16 September 2026: *"Again, ensure proper order. Those that are
 * 'Yes' dependent should be at the bottom of the list."*
 *
 *   10  Proof of Business Registration                 paper 1, always
 *   20  Sketch and photos of location of business      paper 5, always
 *   30  SPA / Authorization, with ID photocopies       paper 6, always
 *   40  Previous Mayor's Permit                        renewals
 *   50  Contract of Lease                              item 8 = Yes
 *   51  Business Permit of Lessor                      item 8 = Yes
 *   52  Tax Declaration / TCT                          item 8 = No
 *   60  Tax Incentive Certificate                      item 7 = Yes
 *
 * Three bands, and the rule is how CERTAIN the row is rather than where it
 * prints:
 *
 *   • 10-30 — asked of everyone. A new filing and a renewal alike open with
 *     the same three lines, which is the part an applicant can act on before
 *     they have decided anything.
 *
 *   • 40 — decided by the FILING, not by an answer. A renewal needs the permit
 *     it is renewing; nothing the applicant types changes that, so it sits
 *     above the answer-driven band rather than inside it.
 *
 *   • 50-60 — decided by the applicant's own answers. Item 3's three rows stay
 *     adjacent because they are one printed requirement with two branches, and
 *     only ever one branch shows. The Tax Incentive Certificate is last of all:
 *     answer-driven AND not on the documentary list at all, so it is the one
 *     row a clerk reconciling the screen against the paper will not find.
 *
 * Gaps are kept for the same reason as before — 40's band and 60's have room
 * to grow without renumbering, and the paper's items 2 and 4 (the Locational
 * Clearance and the Occupancy Permit, which BizTrack issues itself) no longer
 * need reserved slots here since position no longer tracks the printed number.
 */
return new class extends Migration
{
    private const ORDER = [
        'DTI_SEC_CDA' => 10,
        'LOCATION_SKETCH' => 20,
        'SPA_AUTHORIZATION' => 30,
        'PRIOR_PERMIT' => 40,
        'LEASE_CONTRACT' => 50,
        'LESSOR_PERMIT' => 51,
        'LAND_TITLE' => 52,
        'TAX_INCENTIVE_CERT' => 60,
    ];

    /** The positions 000060 set, so `down()` returns to the paper's order. */
    private const PREVIOUS = [
        'DTI_SEC_CDA' => 10,
        'LEASE_CONTRACT' => 30,
        'LESSOR_PERMIT' => 31,
        'LAND_TITLE' => 32,
        'LOCATION_SKETCH' => 50,
        'SPA_AUTHORIZATION' => 60,
        'TAX_INCENTIVE_CERT' => 70,
        'PRIOR_PERMIT' => 80,
    ];

    public function up(): void
    {
        $this->apply(self::ORDER);
    }

    public function down(): void
    {
        $this->apply(self::PREVIOUS);
    }

    private function apply(array $positions): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            echo "  no BUSINESS permit type — nothing to reorder\n";

            return;
        }

        $rows = DB::table('permit_type_requirements')->where('permit_type_id', $business)->count();
        $moved = 0;
        foreach ($positions as $code => $position) {
            $doc = DB::table('document_types')->where('code', $code)->value('id');
            if ($doc === null) {
                continue;
            }
            $moved += DB::table('permit_type_requirements')
                ->where('permit_type_id', $business)
                ->where('document_type_id', $doc)
                ->update(['display_order' => $position, 'updated_at' => now()]);
        }

        echo sprintf("  business-permit requirements %d; repositioned %d\n", $rows, $moved);

        // A row left at the default has no band, and would sort after the lot.
        $unplaced = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->where('display_order', 100)
            ->count();
        if ($unplaced > 0) {
            echo sprintf("  WARNING: %d business row(s) have no position\n", $unplaced);
        }
    }
};
