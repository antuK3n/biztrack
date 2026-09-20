<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a carried-over fee is FOR, when its permit type does not say.
 *
 * ── Why the permit type stopped being enough ───────────────────────────────
 *
 * `unbilled_permit_fees` was built for one thing: a clearance renewed mid-year
 * and issued unpaid, collected at the next January business-permit renewal. For
 * those the permit type IS the description — the January bill reads "Sanitary
 * Permit fee (Jun 2026, unbilled until now)" and that is exactly right.
 *
 * Client, 19 September 2026: *"every other permit renewal and every amendment
 * done before business permit renewal on January will have their fee amounts
 * stacked up until they are ready to be paid on the business permit renewal on
 * January."*
 *
 * An amendment is not a permit. It amends the BUSINESS permit, so that is the
 * permit type its row carries and the foreign key stays honest — but labelling
 * the line from that type would print "Mayor's / Business Permit fee" beside
 * the business permit's own renewal fee, two lines that look like the same
 * charge twice. This column lets the row say what it actually is.
 *
 * Nullable, and the label falls back to the permit type when it is absent. The
 * existing rows are all clearance fees, where the fallback is the right answer
 * and always was — so nothing is back-filled and nothing changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('unbilled_permit_fees', 'description')) {
            return;
        }

        Schema::table('unbilled_permit_fees', function (Blueprint $table) {
            $table->string('description')->nullable()->after('permit_type_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('unbilled_permit_fees', 'description')) {
            return;
        }

        Schema::table('unbilled_permit_fees', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
