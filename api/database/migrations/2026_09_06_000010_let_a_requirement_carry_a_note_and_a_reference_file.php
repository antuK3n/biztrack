<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two fields the Create Other Requirement form asks for and the table could
 * not hold.
 *
 * `additional_remarks` is deliberately NOT the existing `remarks` column. That
 * one carries the office's verdict — the reason a document was sent back — and
 * is written at review time. This is a note written when the requirement is
 * RAISED, and the applicant reads both at once: "here is what I need, here is a
 * note about it" and, later, "here is why what you sent was not enough". Folding
 * them into one column would mean raising a requirement with a note, then
 * rejecting a submission, silently overwrites the note the applicant was still
 * working from.
 *
 * `reference_path` / `reference_name` hold an optional file the OFFICE attaches
 * — a blank form, a template, a sample of what a valid certificate looks like.
 * It is separate from `file_path`, which is the applicant's answer coming the
 * other way; a single column would have the office's template replaced by the
 * applicant's upload the moment they respond.
 *
 * All nullable, no backfill: every existing requirement simply has neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('officer_requests', function (Blueprint $table) {
            $table->text('additional_remarks')->nullable()->after('description');
            // Path on the private disk, and the name the officer's file had.
            $table->string('reference_path')->nullable()->after('additional_remarks');
            $table->string('reference_name')->nullable()->after('reference_path');
        });
    }

    public function down(): void
    {
        Schema::table('officer_requests', function (Blueprint $table) {
            $table->dropColumn(['additional_remarks', 'reference_path', 'reference_name']);
        });
    }
};
