<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The business owner's home address [checklist 2026-09-28, Register 2 — "Make
 * sure that profile details are complete (like home details)"; the same
 * question Mike raised on Edit Settings, "Lalagay pa ba additional info sa
 * profile, like home address?", which the client answered yes].
 *
 * ── Why columns on `users`, not a row in `business_addresses` ─────────────
 *
 * The business address table was the obvious thing to reuse and it is the
 * wrong shape for this:
 *
 *  - It is keyed on `business_id` and cascades with the business. A home
 *    address belongs to the PERSON, exists before they own anything, and must
 *    survive a business being removed from the register.
 *  - Its `barangay_id` is a foreign key into Malabon's own barangay list,
 *    because a business permit is only ever issued for premises inside the
 *    city. An owner may LIVE in Navotas, Caloocan or a province and run a shop
 *    here — the barangay has to be free text, and the city and province have to
 *    be answers rather than the table's 'Malabon' / 'Metro Manila' defaults.
 *  - There is exactly one home address per account, so a separate table buys a
 *    join on every /auth/me and nothing else.
 *
 * ── The parts ────────────────────────────────────────────────────────────
 *
 * The same parts the business address is written in, so the two read alike on
 * a screen and a clerk comparing them is comparing like with like:
 * house no. / building / street in one line (`home_street`, the way people
 * actually write it — "Blk 4 Lot 12, Sampaguita St., Villa Rosa Subd." does not
 * split cleanly into columns, and nothing here computes on its parts), then
 * barangay, city or municipality, province, and ZIP. ZIP is the only optional
 * one: many people do not know theirs, and nothing downstream needs it.
 *
 * ── Why every column is nullable ────────────────────────────────────────
 *
 * Staff accounts have no use for a home address and are never asked for one,
 * and every owner who registered before today has none. Registration enforces
 * it for new owners (AuthController::register); the rest are prompted on their
 * Profile rather than blocked — whether filing should wait for it is an open
 * question for BPLO (docs/questions-for-malabon.md, A27).
 *
 * down() drops the five columns and with them every address an owner has
 * given. Bringing it back means re-running this migration and asking again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('home_street', 255)->nullable()->after('mobile_number');
            $table->string('home_barangay', 100)->nullable()->after('home_street');
            $table->string('home_city', 100)->nullable()->after('home_barangay');
            $table->string('home_province', 100)->nullable()->after('home_city');
            $table->string('home_postal_code', 10)->nullable()->after('home_province');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['home_street', 'home_barangay', 'home_city', 'home_province', 'home_postal_code']);
        });
    }
};
