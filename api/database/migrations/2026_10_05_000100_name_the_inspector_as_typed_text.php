<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The inspector, as a name the office types.
 *
 * The client, 5 October 2026, on the For Inspection card that named an account
 * with Assign/Unassign beside it: *"since an inspector can have no account in
 * the system, would it be better if the admin just type the name of the
 * inspector assigned? The officer in charge is still the one to approve or
 * reject the inspection, but he/she must still be able to put the inspector
 * name just for the record. The field must be editable."*
 *
 * Nullable and free text: blank is allowed, and it is never required before a
 * result is recorded. 120 is the request's own limit; the column is wider only
 * because a string column costs nothing extra.
 *
 * `inspector_user_id` stays, for the rows that already carry one — it is still
 * read by the office boundary on those rows and by the admin's caseload — but
 * nothing writes it on a new visit any more.
 *
 * Schema only: no row is written, so there is nothing to count either side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->string('inspector_name', 160)->nullable()->after('inspector_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn('inspector_name');
        });
    }
};
