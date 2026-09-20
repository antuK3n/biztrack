<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undoes a question the paper never asks.
 *
 * MCG-BPLO-FO-001's last documentary requirement reads "Special power of
 * attorney (SPA)/Authorization to transact for representative together with
 * photocopies of IDs". Two migrations ago that was read as implying a question
 * — is somebody filing on the owner's behalf? — and `filed_by_representative`
 * was added to `applications` to answer it, with a Yes/No control on the
 * wizard's Documentary Requirements step.
 *
 * The client removed it: *"I don't think this is present in the paper form. Do
 * not add any extra things."* They are right, and the mistake is worth naming
 * because it is an easy one to repeat. The paper does not ASK a condition here.
 * It STATES one, inside the requirement's own wording, and leaves the applicant
 * to see whether it applies to them — the same way "Occupancy Permit (if
 * required)" and "(if leased) / (if owned)" are written on the same list.
 *
 * So the condition goes back into the text. Both rows become context 'all' and
 * NOT mandatory, and their wording carries the "if". An applicant who sends a
 * liaison officer attaches the SPA; one who comes themselves does not, and
 * neither is asked a question the counter's form does not put to them.
 *
 * ── Why the two gated contexts could not simply stay ──────────────────────
 *
 * Because nothing would resolve them. 'rented', 'owned' and 'tax_incentives'
 * each read a real answer the paper does ask for (section B items 8 and 7).
 * 'representative' had only the invented column behind it, so with the column
 * gone the two requirements would have matched nothing and silently vanished
 * from every filing — the SPA asked of nobody, which is where this started.
 *
 * The COLUMN is dropped rather than left dead. It was created today, no row
 * ever held a true value (checked: 0 of 7), and `businesses.pays_rent` is the
 * cautionary case — a column nothing wrote, which sat in the schema long enough
 * that the field it belonged to had to be rediscovered from the paper.
 */
return new class extends Migration
{
    public function up(): void
    {
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');

        if ($business !== null) {
            $rows = [
                'SPA_AUTHORIZATION' => 'Paper item 6 — attach this if somebody is transacting on your behalf.',
                'VALID_ID' => 'Paper item 6 — the representative&rsquo;s ID, attached with their authorisation.',
            ];

            foreach ($rows as $code => $notes) {
                $typeId = DB::table('document_types')->where('code', $code)->value('id');
                if ($typeId === null) {
                    continue;
                }

                DB::table('permit_type_requirements')
                    ->where('permit_type_id', $business)
                    ->where('document_type_id', $typeId)
                    ->update(['context' => 'all', 'is_mandatory' => false, 'notes' => $notes]);
            }
        }

        if (Schema::hasColumn('applications', 'filed_by_representative')) {
            $held = DB::table('applications')->where('filed_by_representative', true)->count();
            echo "  applications with filed_by_representative = 1: {$held} (dropping the column)\n";

            Schema::table('applications', function (Blueprint $table) {
                $table->dropColumn('filed_by_representative');
            });
        }

        $optional = DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->where('is_mandatory', false)
            ->count();

        echo "  business-permit requirements now optional: {$optional}\n";
    }

    public function down(): void
    {
        /*
         * Deliberately does NOT recreate the column. Rolling back to a state
         * that asks a question the paper does not ask would be restoring the
         * defect; the requirement gating is put back so the two rows are
         * mandatory again, and that is as far as an honest reversal goes.
         */
        $business = DB::table('permit_types')->where('code', 'BUSINESS')->value('id');
        if ($business === null) {
            return;
        }

        $ids = DB::table('document_types')
            ->whereIn('code', ['SPA_AUTHORIZATION', 'VALID_ID'])
            ->pluck('id');

        DB::table('permit_type_requirements')
            ->where('permit_type_id', $business)
            ->whereIn('document_type_id', $ids)
            ->update(['is_mandatory' => true]);
    }
};
