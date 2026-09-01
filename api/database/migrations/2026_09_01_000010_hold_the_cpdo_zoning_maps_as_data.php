<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPDO's per-barangay zoning maps, held as rows rather than as a literal in code.
 *
 * ## What arrived
 *
 * Twenty-one official sheets, one per barangay: "Brgy. <Name> Proposed Zoning Map
 * 2018 - 2027", City of Malabon, 1:3,000 (Santulan's own sheet is 1:6,000),
 * Luzon 1911 / Philippine Zone III, prepared by the City Planning and Development
 * Department in 2017. Every sheet carries the same 19-entry classification
 * legend. The images themselves live in `web/public/zoning-maps/` as static
 * assets; the three tables below hold everything that points at them or is read
 * off them.
 *
 * ## Why tables and not a PHP array
 *
 * The sheets say "PROPOSED", and they are dated to a plan period that ends in
 * 2027. Two things about them are therefore expected to move: which
 * classification a given block carries, and whether this 2018-2027 proposal is
 * the map actually in force (asked of CPDO in `docs/questions-for-malabon.md`
 * C2/C4). Data that is known in advance to go stale must not need a deploy to
 * correct.
 *
 * The precedent in this repo is `office_signatories`: names on printed forms are
 * rows because a name compiled into a template keeps printing after the
 * officeholder has moved on. A classification revised by a new ordinance fails
 * the same way, so it gets the same treatment.
 *
 * ## What these tables deliberately do NOT support
 *
 * There is no geometry here, and none is implied. A raster sheet cannot answer
 * "what is the zoning at THIS address" — that needs vector polygons and a
 * georeference, and tracing polygons off pixels is guesswork that would tell an
 * applicant their site conforms when the city says it does not. See the comment
 * on `MALABON_BOUNDS` in `web/src/pages/applicant/ApplyWizard.tsx`, which refuses
 * the same overstatement about the city boundary. `barangay_zoning_classification`
 * records only that a classification APPEARS SOMEWHERE on that barangay's sheet.
 * CPDO remains the decider. If the vector data ever arrives, a real per-location
 * check becomes a contained change: a geometry table and a point-in-polygon
 * lookup, with these rows still standing as the human-readable summary.
 *
 * ## Safety
 *
 * Purely additive: two new tables, and one nullable column on `barangays`. No
 * existing row is read, rewritten or deleted, and every existing writer keeps
 * working untouched. The register held 21 barangays / 795 businesses / 1,711
 * applications when this was written, and this migration changes none of those
 * counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zoning_classifications')) {
            Schema::create('zoning_classifications', function (Blueprint $table) {
                $table->id();
                /*
                 * `code` is our stable handle, `name` is CPDO's legend wording.
                 * They are not the same string even where they look it: the
                 * legend writes "R-2 Basic" and "General Commercial Zone", which
                 * are fine to read and poor to key on. Renaming a classification
                 * is then a one-column edit that breaks no lookup.
                 */
                $table->string('code')->unique();
                $table->string('name');
                /*
                 * The legend swatch, as #rrggbb, sampled off the sheets. Kept so
                 * the applicant's zone list can be read against the map they are
                 * looking at without a second palette existing somewhere in the
                 * frontend to drift out of step with this one. Nullable because a
                 * classification added later by ordinance may have no swatch yet.
                 */
                $table->string('legend_color', 7)->nullable();
                // CPDO's own ordering on the sheet, not alphabetical: the
                // residential tiers run R-1 upward and that sequence is meaning.
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('barangay_zoning_classification')) {
            Schema::create('barangay_zoning_classification', function (Blueprint $table) {
                $table->id();
                $table->foreignId('barangay_id')->constrained()->cascadeOnDelete();
                $table->foreignId('zoning_classification_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                // One row per pair. Without this an admin toggling a
                // classification twice leaves the barangay listing it twice.
                $table->unique(['barangay_id', 'zoning_classification_id'], 'barangay_zoning_unique');
            });
        }

        if (! Schema::hasColumn('barangays', 'zoning_map_path')) {
            Schema::table('barangays', function (Blueprint $table) {
                /*
                 * A path under the web app's public root, e.g.
                 * "/zoning-maps/acacia.png". A column and not a slug derived from
                 * the name, so that replacing a sheet — which will happen, these
                 * are proposals — is a row change rather than a rename plus a
                 * rebuild. Nullable: a barangay with no sheet on file must be
                 * able to say so rather than 404 an image.
                 */
                $table->string('zoning_map_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('barangays', 'zoning_map_path')) {
            Schema::table('barangays', function (Blueprint $table) {
                $table->dropColumn('zoning_map_path');
            });
        }
        Schema::dropIfExists('barangay_zoning_classification');
        Schema::dropIfExists('zoning_classifications');
    }
};
