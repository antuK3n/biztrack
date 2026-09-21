<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The one box an applicant can skip goes after the ones they cannot.
 *
 * `2026_09_16_000070` sorted the business permit's requirements by how certain
 * each one is — asked of everyone, then decided by the filing, then decided by
 * an answer. It sorted by certainty and not by OBLIGATION, and those come apart
 * in exactly one place: the SPA / Authorization is shown to everyone, which put
 * it third, and it is optional, which put five required rows below it.
 *
 * So the applicant met the one box they may leave empty before the five they
 * may not. On a step whose whole job is "here is what you still owe us", that
 * is the wrong row to lead with.
 *
 *   10  Proof of Business Registration        everyone, required
 *   20  Sketch and photos of location         everyone, required
 *   40  Previous Mayor's Permit               renewals, required
 *   50  Contract of Lease                     item 8 = Yes, required
 *   51  Business Permit of Lessor             item 8 = Yes, required
 *   52  Tax Declaration / TCT                 item 8 = No, required
 *   60  Tax Incentive Certificate             item 7 = Yes, required
 *   70  SPA / Authorization                   everyone, OPTIONAL
 *
 * Only that one row moves, 30 → 70. Position 30 is left empty on purpose: it is
 * now the slot for another always-required row, which is where one would belong.
 *
 * ── The tension this creates, stated rather than buried ────────────────────
 *
 * The standing instruction was *"those that are 'Yes' dependent should be at
 * the bottom of the list"*, and a row that is not Yes-dependent now sits below
 * them, so on a literal reading this walks it back. It does not reintroduce the
 * fault that instruction was aimed at: the complaint was that answer-driven
 * rows were INTERLEAVED with certain ones, so the applicant read the list with
 * holes punched through the middle of it. The answer-driven rows are still one
 * unbroken block; one optional row now follows the block instead of preceding
 * it. The client chose this having been shown both orders and the tension,
 * 16 September 2026.
 *
 * ── What was NOT done, and why ─────────────────────────────────────────────
 *
 * The same review asked where the Tax Declaration / TCT had gone. It had gone
 * nowhere: it is paper item 3's other branch — "Contract of Lease AND Business
 * Permit of Lessor (if leased), OR Tax Declaration / TCT (if owned)" — and no
 * applicant is ever on both sides of that "or", so answering Yes to item 8
 * correctly hides it.
 *
 * Two changes were considered and declined. Showing it as a live requirement
 * regardless would ask an owner-occupier for a lease they do not hold, which is
 * an unclearable block wearing the costume of an ordinary missing document.
 * Showing it greyed out as "not required, because you pay rent" would keep the
 * printed list whole on screen at the price of a row some applicants will try
 * to upload to. The client chose neither: each row already says why it is there
 * ("Asked because you answered Yes to item 8"), and the applicant only needs to
 * know what THEY owe. Reconciling against the full printed list is the clerk's
 * job and happens on the officer review screen.
 */
return new class extends Migration
{
    private const CODE = 'SPA_AUTHORIZATION';

    private const LAST = 70;

    private const PREVIOUS = 30;

    public function up(): void
    {
        $this->move(self::LAST);
    }

    public function down(): void
    {
        $this->move(self::PREVIOUS);
    }

    private function move(int $position): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        $doc = DB::table('document_types')->where('code', self::CODE)->value('id');

        if ($business === null || $doc === null) {
            echo '  no BUSINESS permit type or no '.self::CODE." — nothing to move\n";

            return;
        }

        $rows = DB::table('permit_type_requirements')->where('permit_type_id', $business)->count();

        $moved = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->where('document_type_id', $doc)
            ->update(['display_order' => $position, 'updated_at' => now()]);

        echo sprintf(
            "  business-permit requirements %d; moved %d (%s -> position %d)\n",
            $rows,
            $moved,
            self::CODE,
            $position,
        );

        /*
         * The property the move is for, checked rather than assumed: nothing
         * optional may sit above anything required. One row moving is easy to
         * get right and just as easy to get right while leaving a second
         * optional row behind, which is the case this would catch.
         */
        $optionalAbove = DB::table('permit_type_requirements as r')
            ->where('r.permit_type_id', $business)
            ->where('r.is_mandatory', false)
            ->whereExists(fn ($q) => $q->from('permit_type_requirements as m')
                ->whereColumn('m.permit_type_id', 'r.permit_type_id')
                ->where('m.is_mandatory', true)
                ->whereColumn('m.display_order', '>', 'r.display_order'))
            ->count();

        if ($optionalAbove > 0) {
            echo sprintf("  WARNING: %d optional row(s) still sit above a required one\n", $optionalAbove);
        }
    }
};
