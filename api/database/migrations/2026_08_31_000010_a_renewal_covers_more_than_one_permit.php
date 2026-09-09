<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A renewal covers the permits the shop actually holds — usually more than one.
 *
 * `applications.prior_permit_id` is a single FK, so the renewal dialog could
 * only ever ask "which ONE permit are you renewing?". That is not the question
 * the counter asks. A shop holding a Mayor's Permit, a Sanitary Permit and an
 * FSIC renews all three in one visit, and forcing the applicant to name one of
 * them made the other two invisible to the filing they were actually part of.
 *
 * `prior_permit_id` is NOT dropped and does not change meaning. It stays the
 * PRIMARY prior permit — the one the renewal chain is keyed on, the one
 * analytics counts, the one the BPLO renewal form prints in its header. The
 * pivot records the full set, primary included, so a reader that wants "every
 * permit this renewal covers" has one place to look and a reader that wants
 * "which permit does this renew" keeps the column it already reads.
 *
 * Written this way round deliberately: widening the column into a pivot and
 * rewriting every caller would have put the renewal chain — the thing
 * docs/ and AGENTS.md both name as how a renewal is identified — at risk for a
 * UI change. The chain is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_prior_permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            /*
             * Restrict, not cascade: a permit that a filing points at is not
             * free to vanish underneath it. Permits are not deleted in the
             * ordinary course anyway — this is the guard for the case that is
             * not ordinary.
             */
            $table->foreignId('permit_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            // The same permit cannot be covered twice by one renewal.
            $table->unique(['application_id', 'permit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_prior_permits');
    }
};
