<?php

use App\Support\TaxClassification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill in `psic_codes.category`, and give it the second key it always needed.
 *
 * The column has existed since the PSIC table was created, for exactly this
 * purpose, and was empty for all 135 codes — so the wizard asked the applicant
 * to classify their own business from a type-ahead of 273 Revenue Code labels.
 *
 * That was mis-billing, not just friction. Two fee groups key on two different
 * lists and the screen offered one box. Measured on a carinderia with
 * ₱1,200,000 of gross sales and 45 sq. m.:
 *
 *   picks "Carinderia"   ₱2,218.25    the ₱550 permit fee, NO business tax
 *   picks "Restaurant"   ₱11,707.00   the ₱9,750 business tax, ₱450 catch-all
 *   both                 ₱11,968.25   correct
 *
 * The more accurate answer lost ₱9,750 — 81% of the bill. So one column cannot
 * do it: `category` carries the Sec. 2J.02 tax class that `business_tax` keys
 * on, and `permit_category` carries the Sec. 3A.03 fine category that
 * `mayors_permit` keys on. `category_branch` names the one follow-up question
 * the Code still forces us to ask.
 *
 * The mapping itself, and the reasoning for all 135 rows, is in
 * App\Support\TaxClassification. It lives in PHP rather than in this migration
 * so that this file and ReferenceSeeder cannot drift apart — both read it.
 *
 * ── What this does to a filing already in flight ──────────────────────────
 *
 * Nothing. It writes only to `psic_codes`, which is reference data. Assessed
 * fees are stored per application in `fee_assessments` and are not recomputed
 * here, and FeeCalculator derives a class only where the filing does not
 * already carry one — so a draft that was filled in under the old question
 * keeps the answer its applicant gave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psic_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('psic_codes', 'permit_category')) {
                $table->string('permit_category')->nullable();
            }
            if (! Schema::hasColumn('psic_codes', 'category_branch')) {
                $table->string('category_branch')->nullable();
            }
        });

        $before = DB::table('psic_codes')
            ->whereNotNull('category')->where('category', '<>', '')->count();

        $written = 0;
        $unknown = [];
        foreach (DB::table('psic_codes')->select('id', 'code')->get() as $row) {
            $map = TaxClassification::FOR_PSIC[$row->code] ?? null;
            if ($map === null) {
                $unknown[] = $row->code;

                continue;
            }
            $written += DB::table('psic_codes')->where('id', $row->id)->update([
                'category' => $map[0],
                'permit_category' => $map[1],
                'category_branch' => $map[2],
                'updated_at' => now(),
            ]);
        }

        $after = DB::table('psic_codes')
            ->whereNotNull('category')->where('category', '<>', '')->count();

        echo sprintf(
            "  psic_codes with a tax class %d -> %d (of %d); rows written %d\n",
            $before,
            $after,
            DB::table('psic_codes')->count(),
            $written,
        );
        echo sprintf(
            "  permit category supplied for %d; catch-all (Sec. 3A.03 item 64) for %d\n",
            DB::table('psic_codes')->whereNotNull('permit_category')->count(),
            DB::table('psic_codes')->whereNull('permit_category')->count(),
        );
        echo sprintf(
            "  ask nothing: %d   ask one Yes/No: %d   still need the picker: %d\n",
            DB::table('psic_codes')->whereNotNull('category')->whereNull('category_branch')->count(),
            DB::table('psic_codes')->whereNotNull('category_branch')->count(),
            DB::table('psic_codes')->whereNull('category')->count(),
        );

        if ($unknown !== []) {
            echo '  WARNING: no mapping for '.implode(', ', $unknown)."\n";
        }
    }

    public function down(): void
    {
        // `category` goes back to empty, which is what it was: the column is
        // reference data this migration populated, not data anyone entered.
        DB::table('psic_codes')->update(['category' => null, 'updated_at' => now()]);

        Schema::table('psic_codes', function (Blueprint $table) {
            foreach (['permit_category', 'category_branch'] as $column) {
                if (Schema::hasColumn('psic_codes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
