<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The details printed on a permit, frozen at the moment it was issued.
 *
 * ── The defect this closes ─────────────────────────────────────────────────
 *
 * `PermitController` builds the PDF's face out of the BUSINESS record, live, at
 * download time — the owner's name, the trade name, the address, the barangay,
 * the lines of business. Nothing about the certificate is stored.
 *
 * So editing a business retroactively rewrote every permit it has ever held.
 * Found while answering the client's question about amendments (19 September
 * 2026), which is where it stops being theoretical: an amendment's whole
 * purpose is editing that record.
 *
 * Three things were wrong with it, in rising order of seriousness:
 *
 *  - a permit downloaded last month and the same permit downloaded today could
 *    differ, with nothing anywhere able to say what it used to read;
 *  - reissuing a permit on amendment would have been pointless, because the old
 *    certificate was already showing the new details;
 *  - and the one that matters. A Sanitary Permit is CHO's statement that THESE
 *    PREMISES were inspected and found sanitary. Change the business address and
 *    that certificate's face asserts CHO inspected somewhere it has never been
 *    — a false statement over the LGU's signature, produced by a screen nobody
 *    thought of as writing anything.
 *
 * `permits.document_hash` has existed all along and nothing has ever written it
 * (measured: 0 of 8 rows). Something was intended here, and live rendering is
 * exactly what would have made a hash meaningless.
 *
 * ── One JSON column rather than seven ─────────────────────────────────────
 *
 * The snapshot is a DOCUMENT FACE: written once, read back whole, never queried
 * and never filtered. Seven nullable columns would have invited exactly the
 * thing this prevents — somebody joining on `permits.address_line` and treating
 * a frozen face as current fact.
 *
 * Nullable, and the renderer falls back to the live business record when it is
 * absent. The 8 permits already in the register were issued before this column
 * existed and cannot be given a truthful snapshot after the fact: what their
 * business looked like on the day is not recoverable. Back-filling from TODAY's
 * record would manufacture a provenance that is not there, so they keep
 * rendering live and their behaviour is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('permits', 'issued_details')) {
            return;
        }

        Schema::table('permits', function (Blueprint $table) {
            $table->json('issued_details')->nullable()->after('document_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('permits', 'issued_details')) {
            return;
        }

        Schema::table('permits', function (Blueprint $table) {
            $table->dropColumn('issued_details');
        });
    }
};
