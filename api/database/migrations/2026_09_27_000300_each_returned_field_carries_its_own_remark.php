<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One remark per returned field, instead of one for all of them.
 *
 * ── Why one blob was not enough ──────────────────────────────────────────────
 *
 * Client, 27 September 2026: *"Allow to put 1 comment/remark per field
 * selected, not just 1 remark for all fields."*
 *
 * Right, and the single box got worse the moment the picker became a checklist
 * hours earlier. An officer ticking three fields had to write one paragraph
 * covering all three — "the registration number does not match the DTI
 * certificate and the form of organization should be Corporation and the trade
 * name is blank" — and the applicant then had to work out which clause belonged
 * to which of the three boxes in front of them. The pointer solved "which
 * field"; the prose went straight back to being a matching exercise.
 *
 * ── Why a table and not JSON on the assignment ───────────────────────────────
 *
 * `remarks_target` is a list of codes and could have grown a parallel list of
 * notes, but the two would then be kept in step by position — and a column
 * whose meaning depends on another column's ordering is the kind of thing that
 * survives exactly until someone filters one of them. A row per field cannot
 * come apart.
 *
 * ── Replaced on every return, like the pointer it accompanies ────────────────
 *
 * `returnMainForm` deletes this filing's notes before writing the new set, for
 * the reason the pointer is replaced rather than merged: a note from a previous
 * round sitting under a field this round is not about tells the applicant to
 * fix something nobody asked about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_return_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            /* The `form:` code this note is about — one of `remarks_target`'s. */
            $table->string('target', 120);
            $table->text('note');
            $table->timestamps();

            /*
             * One note per field per filing. The unique index is the guarantee
             * rather than a convention: the write path replaces the whole set,
             * and a bug that inserted instead of replacing would otherwise show
             * up as an applicant seeing the same field twice with two different
             * instructions.
             */
            $table->unique(['application_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_return_notes');
    }
};
