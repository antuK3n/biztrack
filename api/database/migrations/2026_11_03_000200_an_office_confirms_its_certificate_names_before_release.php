<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The names the OBO confirmed for one Certificate of Occupancy before it is
 * released [client, 3 November 2026: "ilagay sa after inspection … bago
 * irelease the admin has to confirm the names"].
 *
 * Kept on the filing's clearance row rather than on the permit, because the
 * permit may not exist yet when they are confirmed: a pass can wait on the
 * payment for its release. Issuing reads them (CertificateSignatories::
 * rowsAtIssue) and freezes them on the permit; null means the office's own.
 *
 * Additive and nullable: nothing already on the register changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->json('confirmed_signatories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->dropColumn('confirmed_signatories');
        });
    }
};
