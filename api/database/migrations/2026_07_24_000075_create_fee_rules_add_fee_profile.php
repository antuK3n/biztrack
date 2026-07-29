<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Revenue-code fee rules (Ordinance A10-2016 + national references).
        // Rates are seeded from database/data/revenue_code/*.json — amending the
        // ordinance is a data change, not a code change.
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // e.g. biztax.manufacturer
            $table->string('title');
            $table->string('section');                 // ordinance/statute citation
            $table->string('source');                  // A10-2016 | RA 9514 | ...
            $table->string('office');                  // BPLO|CHO|BFP|OBO|CENRO|CMO-MARKET|CTO
            $table->string('group');                   // business_tax|mayors_permit|regulatory|...
            $table->json('permit_types');              // requested types that trigger the rule
            $table->json('conditions');                // fee-profile predicates
            $table->string('basis');                   // gross_sales|capitalization|fixed|...
            $table->json('computation');               // {type, amount|rate|brackets|excess|...}
            $table->json('cap')->nullable();           // {max_amount, uplift_rate?}
            $table->text('notes')->nullable();
            $table->json('defects')->nullable();       // source-print anomalies (Appendix A)
            $table->json('constants')->nullable();     // engine constants (penalty rule)
            $table->boolean('requires_officer')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['group', 'active']);
        });

        // Facts the calculator consumes: capitalization, gross sales, lines of
        // business with categories, floor area, employees, flags, etc.
        // (docs/revenue-code-extract.md Appendix B).
        Schema::table('applications', function (Blueprint $table) {
            $table->json('fee_profile')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('fee_profile');
        });
        Schema::dropIfExists('fee_rules');
    }
};
