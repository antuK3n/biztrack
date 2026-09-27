<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A requirement the SYSTEM raised, and which rule raised it.
 *
 * ── Why a column and not a title match ───────────────────────────────────────
 *
 * Every row in `officer_requests` until now was typed by an officer, so the
 * only way to recognise one was to read its prose. That is fine while nothing
 * acts on it. It stops being fine the moment a requirement's ANSWER has a
 * destination: the first automated requirement — the blank TIN, raised at BPLO
 * approval — writes the applicant's reply into `businesses.tin` when the office
 * accepts it, and deciding that by matching `title === 'Tax Identification
 * Number (TIN)'` would mean an officer who types that phrase by hand gets their
 * applicant's free text written into the business record.
 *
 * A key the system owns cannot be typed by accident.
 *
 * ── Nullable, and the overwhelming majority stay null ────────────────────────
 *
 * An officer's own requirement has no key and needs none — it is read by a
 * person, answered to a person, and closed by a person. This names only the
 * ones a rule raised and a rule must recognise again later.
 *
 * Indexed because the lookup is "does this filing already carry the TIN
 * requirement", which runs on every BPLO approval and must not become a scan
 * of every requirement ever raised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('officer_requests', function (Blueprint $table) {
            $table->string('system_key')->nullable()->after('request_type');
            $table->index(['application_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::table('officer_requests', function (Blueprint $table) {
            $table->dropIndex(['application_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
