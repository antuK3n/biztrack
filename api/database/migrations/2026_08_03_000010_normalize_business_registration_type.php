<?php

use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checklist item 94 — collapse the two vocabularies in
 * `businesses.registration_type` down to the four organisation structures.
 *
 * The column was holding both the registering AGENCY ("DTI", "SEC", "CDA",
 * written by the seeders) and the STRUCTURE ("sole_proprietorship",
 * "partnership", written by the wizard), because the form asked for a
 * "DTI / SEC / CDA Registration Number" and a "Type of Registration" as though
 * they were independent facts. They are not: the agency follows from the
 * structure. Going forward the column holds the structure and the agency is
 * derived (Business::REGISTRAR_BY_FORM), so the mixture has to be resolved.
 *
 * The dangerous part is "SEC", which registers BOTH partnerships and
 * corporations. An agency code can never be reversed into a structure on its
 * own, and turning 143 businesses into corporations because that is the more
 * common case would be inventing a legal fact about somebody else's company.
 *
 * So this migration resolves rows in three passes, and NEVER guesses:
 *
 *   1. The row already answers it. `businesses.form_of_organization` is a
 *      separate column that AnalyticsHistorySeeder fills with the true
 *      structure while writing the agency into registration_type. Where it is
 *      set, it IS the answer — no inference at all, just reading the other half
 *      of the same row. This resolves the great majority of the SEC rows.
 *   2. The mapping is one-to-one. "DTI" only ever registers sole proprietors and
 *      "CDA" only ever registers cooperatives, so those two can be rewritten
 *      with no information lost.
 *   3. Everything else is left exactly as it was. In practice that is a legacy
 *      "SEC" row with no form_of_organization: we do not know, so the column
 *      keeps saying "SEC" and the code reads it as unknown
 *      (Business::normalizeRegistrationType returns null for it). The wizard
 *      then asks that applicant to confirm Partnership or Corporation the next
 *      time they file, which is the only source that can actually answer it.
 *
 * The reverse pass keeps `form_of_organization` in step: rows the wizard wrote
 * carry the structure in registration_type but left form_of_organization null,
 * so the Form of Organization panel read empty for every business the
 * application itself registered.
 *
 * No rows are deleted and no row is left without a registration_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        $agencies = array_keys(array_flip(Business::REGISTRAR_BY_FORM)); // DTI, SEC, CDA

        DB::transaction(function () use ($agencies) {
            /*
             * Pass 1 — the row already knows. Copy the structure the seeder
             * recorded in form_of_organization over the agency code. This is a
             * read, not a guess: nothing is inferred from "SEC" itself.
             */
            foreach (Business::ORGANIZATION_FORMS as $form) {
                DB::table('businesses')
                    ->whereIn('registration_type', $agencies)
                    ->where('form_of_organization', $form)
                    ->update(['registration_type' => $form]);
            }

            /*
             * Pass 2 — the unambiguous agencies. DTI registers business names
             * for sole proprietors and CDA registers cooperatives; neither
             * covers a second structure, so nothing is lost.
             *
             * "SEC" is deliberately not in this list.
             */
            DB::table('businesses')
                ->where('registration_type', 'DTI')
                ->update(['registration_type' => 'sole_proprietorship', 'form_of_organization' => 'sole_proprietorship']);

            DB::table('businesses')
                ->where('registration_type', 'CDA')
                ->update(['registration_type' => 'cooperative', 'form_of_organization' => 'cooperative']);

            /*
             * Pass 3 — the other direction. Where the wizard wrote a structure
             * into registration_type but form_of_organization was never filled
             * (mass assignment used to drop it), make the two columns agree.
             */
            foreach (Business::ORGANIZATION_FORMS as $form) {
                DB::table('businesses')
                    ->where('registration_type', $form)
                    ->whereNull('form_of_organization')
                    ->update(['form_of_organization' => $form]);
            }

            /*
             * Anything still holding "SEC" stays. Left on purpose — see the
             * class comment. Whatever remains here is a business whose
             * partnership-or-corporation question only its owner can answer.
             */
        });
    }

    /**
     * Not reversed.
     *
     * Rewriting the four structures back to agency codes would be lossy in the
     * direction that matters — every partnership and every corporation would
     * come back as the single string "SEC", destroying the distinction this
     * migration exists to preserve. It would also hit rows that already held a
     * structure before this ran, which were never the migration's to change.
     *
     * A no-op down() is the honest answer: the data is strictly better
     * described after up() than before, and there is nothing to restore.
     */
    public function down(): void {}
};
