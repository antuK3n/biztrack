<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is filing — the owner, or somebody transacting for them.
 *
 * MCG-BPLO-FO-001's documentary requirements list ends with "Special power of
 * attorney (SPA)/Authorization to transact for representative together with
 * photocopies of IDs". That requirement applies to one kind of applicant and
 * not the other, and nothing in the register could tell them apart.
 *
 * The zoning sheet already makes this distinction, but it makes it from
 * `authorized_representative` on the CPDD office form — which is only reachable
 * AFTER payment, in the clearance stage. The BPLO wizard runs before any of
 * that, so it cannot borrow the answer; it has to ask.
 *
 * ── On the application, not the business ──────────────────────────────────
 *
 * Who files can differ from one filing to the next: an owner may lodge the new
 * application themselves and send a liaison officer with next year's renewal.
 * Putting it on `businesses` would make the most recent filing's answer the
 * standing truth about every earlier one.
 *
 * Defaults to false, which is the common case and the safe one: false asks for
 * no SPA, and an applicant who does use a representative ticks the box. The
 * opposite default would demand an authorisation letter from every sole
 * proprietor filing in person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('filed_by_representative')
                ->default(false)
                ->after('data_privacy_consent');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('filed_by_representative');
        });
    }
};
