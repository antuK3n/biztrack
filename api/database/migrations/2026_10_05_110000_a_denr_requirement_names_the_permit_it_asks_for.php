<?php

use App\Support\DenrRequirements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every DENR requirement a `system_key`, and every DENR permit a
 * document type to be filed under.
 *
 * Client, 5 October 2026: *"Remember the sending of TIN and DENR in Other
 * Requirements? Can you make them show a field instead of a 'Response'
 * thingy?"* A field needs to know what it is a field FOR. The TIN requirement
 * has carried `system_key = business.tin` since it was added; the DENR rows
 * never carried one — `raiseDenrRequirements` keyed them on their title, and
 * the Other Requirements page told them apart by reading "DENR " off the
 * front of it. A title is prose an officer can type, so it cannot decide
 * what an upload is filed as.
 *
 * ── The backfill ──────────────────────────────────────────────────────────
 *
 * Only rows the SYSTEM raised (`requested_by_user_id` null), with no key
 * yet, whose title is exactly the shape the workflow writes: "DENR <code> —
 * …" with <code> one of the six on MCG-CENRO-FO-001's legend. An officer's
 * hand-typed "DENR ECC — please" has an author and is left alone.
 *
 * No row is created or deleted; one column is filled on rows that had it
 * null. `down()` empties it again on exactly the keys this wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        $typesBefore = DB::table('document_types')->count();
        $keyedBefore = DB::table('officer_requests')->where('system_key', 'like', 'denr.%')->count();

        foreach (array_keys(DenrRequirements::DOCUMENT_NAMES) as $code) {
            DB::table('document_types')->insertOrIgnore([
                'code' => 'DENR_'.$code,
                'name' => DenrRequirements::DOCUMENT_NAMES[$code],
                'help_text' => 'Issued by the DENR. Due to CENRO within six months of your City Environmental Certificate.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('officer_requests')
                ->whereNull('requested_by_user_id')
                ->whereNull('system_key')
                ->where('title', 'like', "DENR {$code} — %")
                ->update(['system_key' => DenrRequirements::systemKey($code)]);
        }

        echo sprintf(
            "  document_types %d -> %d; DENR requirements keyed %d -> %d (officer_requests rows %d, unchanged)\n",
            $typesBefore,
            DB::table('document_types')->count(),
            $keyedBefore,
            DB::table('officer_requests')->where('system_key', 'like', 'denr.%')->count(),
            DB::table('officer_requests')->count(),
        );
    }

    public function down(): void
    {
        // The keys only. A document type may already have files filed under
        // it by then, and dropping it would orphan them from their own name.
        DB::table('officer_requests')
            ->whereIn('system_key', array_map(
                fn (string $code) => DenrRequirements::systemKey($code),
                array_keys(DenrRequirements::DOCUMENT_NAMES),
            ))
            ->update(['system_key' => null]);
    }
};
