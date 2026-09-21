<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paper item 3's owned branch, spelled exactly as the paper spells it.
 *
 * `2026_09_16_000060` renamed this row from "Land Title or Tax Declaration" —
 * a paraphrase — to the paper's own two documents, but set it with spaces
 * around the slash. The printed form has none:
 *
 *   "Tax Declaration/Transfer Certificate of Title (TCT) (if owned)"
 *
 * The client, 16 September 2026: *"Just specify it to be the same as what is
 * stated on the paper - Tax Declaration/Transfer Certificate of Title (TCT)"*.
 *
 * A space is not worth a migration on its own merits. It is worth one here
 * because of what this particular name is FOR: these rows are read beside the
 * printed checklist, and the standing rule recorded in ReferenceSeeder is that
 * they carry the paper's wording so the two can be reconciled line by line.
 * Every departure from that, however small, is a place where the next person
 * has to decide whether the difference means something.
 *
 * Note that the sibling rows are NOT changed to match. "(DTI / SEC / CDA)" is
 * already an abbreviation of a much longer printed phrase — "(DTI for Sole
 * Proprietor/SEC for Corporation, Partnership and OPC/CDA for Cooperative)" —
 * so its spacing is ours to choose, not the paper's to dictate. This row quotes
 * the paper in full, and so it quotes it exactly.
 */
return new class extends Migration
{
    private const AS_PRINTED = 'Tax Declaration/Transfer Certificate of Title (TCT)';

    private const PREVIOUS = 'Tax Declaration / Transfer Certificate of Title (TCT)';

    public function up(): void
    {
        $this->rename(self::AS_PRINTED);
    }

    public function down(): void
    {
        $this->rename(self::PREVIOUS);
    }

    private function rename(string $name): void
    {
        $changed = DB::table('document_types')
            ->where('code', 'LAND_TITLE')
            ->update(['name' => $name, 'updated_at' => now()]);

        echo sprintf("  LAND_TITLE renamed: %d row(s) -> \"%s\"\n", $changed, $name);
    }
};
