<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A returned permit says WHAT to fix, not only that something is wrong.
 *
 * ── The problem this solves, and the one it deliberately does not ──────────
 *
 * `application_permit_types.remarks` already holds the officer's reason for
 * sending a permit back, as free prose, and that is the right shape for it: the
 * client's own point is that there are thousands of possible reasons and no
 * dropdown will ever cover them.
 *
 * What prose cannot do is tell the SYSTEM which row of the checklist or which
 * answer on the sheet it is about. The tempting fix is to read the text —
 * match "declaration" against a document type, look for field names — and that
 * is exactly the matching problem the client was worried about. It fails on
 * synonyms, on Filipino, on typos, on "the second one", and it fails silently.
 *
 * So the text is never parsed. This column carries a POINTER beside it: a
 * stable code the system already owns — a `document_types.code` for a
 * checklist row, or an office-form answer key for a field. Two columns, two
 * jobs. Highlighting the right row becomes a key lookup; "what do we return
 * most often" becomes a GROUP BY that is immune to phrasing.
 *
 * ── Nullable, and it stays nullable ───────────────────────────────────────
 *
 * Ticking a target is optional for the officer. A return with no pointer is a
 * perfectly good return — "your kitchen layout does not meet PD 856, ring us" —
 * and the applicant still gets the prose and an editable sheet. Making it
 * required would turn free text into a form, which is the opposite of the
 * decision this implements.
 *
 * No backfill. The three rows carrying remarks today are approvals and one
 * return whose target nobody recorded, so there is nothing to infer and
 * guessing would put a wrong highlight on a real filing.
 *
 * NOT a foreign key. The pointer can name a document type OR a form answer key,
 * and the second has no table — the office sheets' fields are defined in PHP
 * (`OfficeFormAnswers`) and in the browser, not in a lookup table. A constraint
 * on half the value space would refuse the other half.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->string('remarks_target')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('application_permit_types', function (Blueprint $table) {
            $table->dropColumn('remarks_target');
        });
    }
};
