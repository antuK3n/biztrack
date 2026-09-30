<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deferred permit fee remembers the penalty it was late by.
 *
 * ── Why the penalty is stored and not computed on the January bill ──────────
 *
 * Secs. 8A.04/8A.05 charge a 25% surcharge once and 2% a month on top, and the
 * client's decision of 1 October 2026 is that the clock runs from the permit's
 * expiry to the day the late renewal was FILED, and stops there:
 *
 *   *"Expiry -> filing, frozen."*
 *
 * The wait until January adds nothing to it. That wait is the LGU's own
 * collection scheme — a sanitary permit renewed in June is issued unbilled
 * because the city collects in January, not because the applicant asked to
 * pay late — and charging interest across it would bill them for a delay they
 * did not choose and could not shorten.
 *
 * "Frozen" is the whole reason these are columns. Recomputing the penalty when
 * the January assessment runs would make the amount depend on WHEN the bill is
 * drawn, so the same June renewal would cost more if BPLO assessed it in
 * February than in January, and re-assessing a filing would move a number the
 * applicant had already been shown. Written once, at issue, and read back
 * unchanged.
 *
 * ── Three columns rather than one ───────────────────────────────────────────
 *
 * `amount` keeps meaning what it has always meant: the permit fee itself. The
 * surcharge and the interest are separate because the January bill prints them
 * as their own line — an applicant is entitled to see that ₱1,375 is ₱1,100 of
 * sanitary fee and ₱275 of penalty, not one unexplained figure — and because
 * they answer different questions later: total fees collected is `amount`,
 * total penalties collected is the other two, and a single merged column could
 * answer neither.
 *
 * `months_late` is kept although it is derivable, because it is not derivable
 * LATER: it was counted against the permit's expiry and the filing date at a
 * moment that has passed, and Sec. 8A.05 caps it at 36 months, so a row at the
 * cap cannot be reconstructed from the amounts alone.
 *
 * Existing rows get zeros, not nulls. There is one unbilled row on the
 * register and it was incurred before any of this was charged; a null would
 * say "unknown", and what is true is that no penalty applies to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unbilled_permit_fees', function (Blueprint $table) {
            $table->decimal('surcharge', 12, 2)->default(0)->after('amount');
            $table->decimal('interest', 12, 2)->default(0)->after('surcharge');
            $table->unsignedSmallInteger('months_late')->default(0)->after('interest');
        });
    }

    public function down(): void
    {
        Schema::table('unbilled_permit_fees', function (Blueprint $table) {
            $table->dropColumn(['surcharge', 'interest', 'months_late']);
        });
    }
};
