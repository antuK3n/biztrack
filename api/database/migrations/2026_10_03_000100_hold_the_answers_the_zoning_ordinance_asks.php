<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The answers City Ordinance No. 24-2018 needs that a filing did not carry.
 *
 * The zoning check (App\Support\Zoning\ZoningCheck) applies the ordinance rule
 * by rule, and many rules turn on a fact nothing else asks: whether the
 * business is run from a home, how far it is from the nearest school, whether
 * the lot is beside a creek, which zone CPDO places the lot in. Those answers
 * are kept on the FILING, because they describe the site at the time it was
 * assessed — the same reason `fee_profile` is on the filing and not the
 * business.
 *
 * Two columns, not one JSON with two keys. The applicant writes the first
 * through the wizard's draft save and the zoning officer writes the second
 * from the review sheet; with one column each side's endpoint would be able to
 * overwrite the other's answers, and keeping them apart is simpler than
 * proving it cannot.
 *
 * `json` maps to TEXT on SQLite and to `json` on PostgreSQL 16, so the same
 * migration runs on the register and in production. Both nullable: every
 * existing filing simply has no answers yet, which the check reads as
 * "not answered" and turns into a question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('zoning_facts')->nullable();
            $table->json('zoning_officer_facts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['zoning_facts', 'zoning_officer_facts']);
        });
    }
};
