<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep the photo behind "Edit Profile Picture".
 *
 * The control has been on the Edit Profile modal since the screen was built
 * (PDF p12) and did nothing when pressed — there was no column to write to, so
 * every avatar in the app was the same gray glyph.
 *
 * ## Why a path and not the image
 *
 * The bytes go to the private disk beside application documents, and only the
 * path is stored here. A photo in a BLOB column travels with every `SELECT *`
 * on `users` — the table read on each authenticated request — and this database
 * is SQLite, where that cost lands on a single file the whole stack shares.
 *
 * ## Why nullable, and why it stays nullable
 *
 * Almost nobody will set one. Every account that exists today has no photo, the
 * seeded accounts have none, and a profile photo is not something a permit
 * clerk should ever be made to supply to correct their own name. Absent means
 * "draw the glyph", which is what the UI did before this column existed.
 *
 * Removing a photo nulls this column and deletes the file, so the two states
 * are the only ones the app has to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Path on the `local` (private) disk, e.g.
            // "private/avatars/7/9f3c….jpg" — the same shape as
            // application_documents.stored_path, and never a URL: the file is
            // served through an authenticated route, not linked to directly.
            $table->string('avatar_path')->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
