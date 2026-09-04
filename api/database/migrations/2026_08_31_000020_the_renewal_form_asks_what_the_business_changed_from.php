<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MCG-BPLO-FO-002 v2.0, section A3: "Amendment: From [structure] To [structure]".
 *
 * The renewal form asks three questions in section A, and only two of them had
 * anywhere to go. A1 ("any changes or amendments in the previous business
 * registration?") is `has_amendments`; A2 (Ownership / Location or Address /
 * Nature of Business / Others) is the four `amendment_*` columns the
 * manuscript-alignment migration created. A3 had no column at all, so a shop
 * converting from Sole Proprietorship to Corporation could tick "Ownership"
 * and then had no way to say what it became — which is the only part of the
 * answer the BPLO can act on.
 *
 * Two plain strings rather than an FK to a lookup: the four structures are
 * printed on the paper form as fixed choices, they are the same four the
 * wizard's REGISTRATION_TYPES already offers, and there is no table of them.
 * Storing the same vocabulary the rest of the schema uses
 * (`businesses.registration_type`) keeps a reader from having to learn a second
 * spelling of "sole_proprietorship".
 *
 * Nullable because section A3 is only reachable when A1 is Yes, and only
 * meaningful when the amendment is one of ownership — a renewal that changes
 * nothing leaves both null, and that null means "not asked", which is the
 * truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('amendment_from_registration_type')->nullable()->after('amendment_other');
            $table->string('amendment_to_registration_type')->nullable()->after('amendment_from_registration_type');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['amendment_from_registration_type', 'amendment_to_registration_type']);
        });
    }
};
