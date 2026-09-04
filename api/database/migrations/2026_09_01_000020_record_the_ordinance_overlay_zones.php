<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three overlay zones City Ordinance No. 24-2018 designates, as rows.
 *
 * ## What an overlay is
 *
 * Art. V §4: "a transparent zone overlain on a Base Zone or another Overlay Zone
 * that provides an additional set (layer) of regulations". A barangay does not
 * have EITHER a base zone OR an overlay — it has base zones AND, where the
 * ordinance says so, an overlay lying over them. Flood covers all 21 barangays;
 * every one of them also carries between four and ten base classifications.
 *
 * ## Separate tables, not a `kind` column on `zoning_classifications`
 *
 * The alternative considered was one discriminator column, `kind` in
 * ('base','overlay'), on the existing table. Rejected on three counts, in
 * increasing order of weight:
 *
 * 1. Every existing read would silently widen. `Barangay::zoningClassifications()`,
 *    `ReferenceController::barangays()` and `ZoningSeeder` all select the whole
 *    table today. Adding rows to it makes each of them wrong until somebody
 *    remembers a `where('kind', 'base')` — and the failure is not a crash, it is
 *    an applicant reading "Flood Overlay Zone" in a list headed "the
 *    classifications drawn on Barangay X". Requirement: the two must not be
 *    confusable. A filter you must remember is confusable; a table that cannot
 *    return the row is not.
 * 2. The columns mean different things. `legend_color` is a swatch sampled off
 *    the CPDO sheet; no overlay has one, because overlays are not in the sheets'
 *    19-entry legend at all. Null would then mean both "no swatch yet" and "this
 *    kind of row never has one".
 * 3. The provenance and the truth condition differ. A `barangay_zoning_classification`
 *    row means "this colour is drawn somewhere on this barangay's raster sheet",
 *    read off pixels by palette match, and correctable by an admin when CPDO says
 *    otherwise. A `barangay_zoning_overlay` row means "the ordinance text
 *    designates this overlay over this barangay" — Art. IV §5's right-hand column
 *    and Annex C's map index, agreeing. Those are two different claims from two
 *    different documents with two different people who can correct them. Merging
 *    them into one table would make the merged table's docblock untrue of half
 *    its rows.
 *
 * It also matches the shape the repo already has. `departments`,
 * `document_types` and `permit_types` are each their own small code/name
 * reference table; there is no typed `reference_items` anywhere here that a
 * fourth kind of thing would join. A narrow table per kind of reference row is
 * the existing convention, not a new one being introduced.
 *
 * ## Codes
 *
 * The ordinance contradicts itself and we do not get to pretend otherwise.
 * Art. IV §3 writes LSD-OZ / HTG-OZ / ET-OZ; Art. V §4 writes FLD-OZ / HTG-OZ /
 * ETM-OZ. Only Heritage agrees. We key on Art. V's spellings — FLD-OZ, HTG-OZ,
 * ETM-OZ — because Art. V is where the overlay's substantive regulations are
 * written, so it is the article a reader checking a code will be holding, and
 * because FLD reads as Flood where LSD reads as nothing. `code` is ours and
 * stable either way; if CPDO confirms Art. IV's spellings are the official ones,
 * that is a `name`/label change and a note here, not a re-key.
 *
 * ## Safety
 *
 * Purely additive: two new tables, nothing else touched. No existing row is
 * read, rewritten or deleted, and `zoning_classifications` keeps exactly the 19
 * rows it has. `down()` drops only the two tables this migration created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zoning_overlays')) {
            Schema::create('zoning_overlays', function (Blueprint $table) {
                $table->id();
                // Ours and stable; see the codes note above for why the ordinance
                // cannot supply one on its own.
                $table->string('code')->unique();
                // The ordinance's own wording, e.g. "Flood Overlay Zone".
                $table->string('name');
                /*
                 * One sentence saying what the overlay IS, from Art. V §4 — not
                 * what it means for a given property, which is CPDO's to say.
                 * A column and not a frontend constant for the same reason the
                 * classifications are rows: an amending ordinance must not need
                 * a deploy.
                 */
                $table->string('description', 500)->nullable();
                // The ordinance's order in Art. IV §3: Flood, Heritage,
                // Eco-Tourism. No swatch column: overlays are not drawn on the
                // sheets' legend, so there is no colour to sample.
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('barangay_zoning_overlay')) {
            Schema::create('barangay_zoning_overlay', function (Blueprint $table) {
                $table->id();
                $table->foreignId('barangay_id')->constrained()->cascadeOnDelete();
                $table->foreignId('zoning_overlay_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                // One row per pair, as with the base-zone pivot: without it an
                // admin toggling an overlay twice leaves it listed twice.
                $table->unique(['barangay_id', 'zoning_overlay_id'], 'barangay_zoning_overlay_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('barangay_zoning_overlay');
        Schema::dropIfExists('zoning_overlays');
    }
};
