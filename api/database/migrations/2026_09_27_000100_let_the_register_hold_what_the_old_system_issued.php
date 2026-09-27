<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room in the register for the city's records from before BizTrack.
 *
 * Ken's checklist, 27 September 2026, "Migration 1": the super admin imports
 * the old system's businesses and their permits from a CSV (or, "Migration 2",
 * straight from an ODBC source). Three facts about that data did not fit the
 * schema, and each gets the honest shape rather than a stand-in:
 *
 * ── An imported business may have no account behind it ─────────────────────
 *
 * `businesses.owner_user_id` was NOT NULL because until now every business was
 * registered by the person who owns it, signed in. A business the city licensed
 * on paper in 2019 has an owner with a name and no BizTrack account. The
 * alternatives to NULL were both worse, for the reason the officer-requests
 * migration of 9 September gives: attributing the business to the admin who ran
 * the import puts a shop in the wrong person's list, and minting a placeholder
 * `users` row means an account nobody can sign in to, with a password somebody
 * had to invent — and the brief says never set one.
 *
 * So NULL owner + `legacy_owner_id` naming who the old system says owns it. The
 * owner claims the business at sign-up by quoting its account or permit number
 * (App\Support\LegacyImport\LegacyClaim), which fills `owner_user_id`.
 *
 * Readers already had to survive a null owner — `User` is soft-deleted, so
 * `$business->owner` could be null before this (AGENTS.md §11). This makes it
 * common rather than rare, which is why every owner read was re-checked.
 *
 * ── An imported permit has no filing behind it ─────────────────────────────
 *
 * `permits.application_id` was NOT NULL for the same reason: every permit was
 * minted by approving a BizTrack application. A certificate issued on paper
 * was not, and inventing an application for it would put a filing in the
 * register — and in every analytics count of filings — that nobody ever filed.
 * `Permit::booted()` already skips the renewal-chain lookup when the id is null.
 *
 * ── Re-importing must update, not duplicate ────────────────────────────────
 *
 * `legacy_id` on businesses and permits holds the old system's own key. It is
 * what the importer matches on, so running the same export twice (or a later,
 * corrected one) updates the rows it made rather than adding a second copy.
 * Unique but nullable: every row BizTrack itself creates leaves it empty, and
 * both SQLite and PostgreSQL allow any number of NULLs under a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The owner as the old system records them. Personal data under
         * RA 10173 — it is here only so the rightful owner can claim the
         * business, and it is never shown to anyone but the super admin.
         */
        Schema::create('legacy_owners', function (Blueprint $table) {
            $table->id();
            // The old system's owner key, when the export has one. Lets one
            // owner of three shops be one row rather than three.
            $table->string('legacy_id')->nullable()->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            // Set when a BizTrack account claims the businesses. Kept rather
            // than the row deleted, so "who claimed this, and when" survives.
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->change();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('legacy_id')->nullable()->unique();
            $table->foreignId('legacy_owner_id')->nullable()->constrained('legacy_owners')->nullOnDelete();
        });

        Schema::table('permits', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->change();
        });

        Schema::table('permits', function (Blueprint $table) {
            $table->string('legacy_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('permits', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_owner_id');
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });

        Schema::dropIfExists('legacy_owners');

        /*
         * NOT NULL is deliberately not reinstated on `owner_user_id` or
         * `application_id` — the same call the officer-requests migration makes.
         * On a register holding any imported row the rebuild would fail, or
         * worse, invent a value. Reversing this means deciding what those rows
         * should say, which is a decision and not a schema change.
         */
    }
};
