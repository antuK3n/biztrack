<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A refusal survives the re-application it causes, and says what would settle it.
 *
 * ── The gap this closes ──────────────────────────────────────────────────────
 *
 * `ClearanceStatus::Rejected` came back on 24 September 2026, because the LGU
 * moved the business permit's release to payment and a refused clearance now
 * SUSPENDS that permit. The way out is to apply for the refused permit again.
 *
 * That route worked and lost its own history. `submitClearanceForm` clears
 * `remarks` when the applicant hands the sheet back in — correctly, because the
 * office's instruction has been answered — so the row returned to the office's
 * queue as a clean `for_approval` with nothing to say it had ever been refused.
 * The officer re-reading it could approve, in good faith, exactly what their
 * office turned down the week before.
 *
 * `returned_at` on the same table exists for the same reason and is the
 * precedent: "it stops being 'how long have they been sitting on this' and
 * becomes the record that this permit was sent back once". A refusal is the
 * heavier act and had no such record at all.
 *
 * ── Three columns, and why not one ───────────────────────────────────────────
 *
 * `rejected_at` answers WHETHER and WHEN, which is what draws the banner.
 *
 * `rejection_note` is the office's reason, kept apart from `remarks` on purpose.
 * `remarks` is the CURRENT instruction and is cleared when answered; this is the
 * historical fact and must not be. Folding them together would mean either
 * clearing the history or leaving a stale instruction on the row, and the two
 * requirements genuinely conflict.
 *
 * `rejection_remedy` is what the office says would settle it — the client's
 * addition, and the one that turns a refusal into something the owner can act
 * on. "No potable water connection" says what is wrong; "connect to mains or
 * file a deep-well permit, then apply again" says what to do about it. The
 * first is a verdict, the second is a route, and an applicant holding a
 * suspended business permit needs the route.
 *
 * ── Additive, and safe on the live register ──────────────────────────────────
 *
 * Three nullable columns, no backfill, no data moved. Every existing row reads
 * null, which is exactly right: nothing in the register has been refused,
 * because the state has existed for less than a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('returned_at');
            $table->text('rejection_note')->nullable()->after('rejected_at');
            $table->text('rejection_remedy')->nullable()->after('rejection_note');
        });
    }

    public function down(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->dropColumn(['rejected_at', 'rejection_note', 'rejection_remedy']);
        });
    }
};
