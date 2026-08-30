<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation is with an OFFICE, not with a filing.
 *
 * The client's line was "messaging (make sure the business owner can only
 * contact the correct offices)", and the reason the system could not honour it
 * is in this table: `message_threads` was `id, application_id, subject, status,
 * timestamps` and `application_id` was UNIQUE. One conversation per filing, and
 * a filing is routed to up to seven offices — so a message had no addressee
 * beyond the filing itself. There was no "correct office" for the server to
 * check against, because the schema had never recorded one.
 *
 * The half-measure that came before this is worth naming, because this replaces
 * it. Item 111 closed the leak on the MESSAGE — an office was shown only the
 * applicant's turns and its own — and MessageController said so in as many
 * words: "Splitting the schema into one thread per office is the fuller fix,
 * but it changes the shape of the applicant's inbox... that is a product
 * decision about their experience, not a leak to be patched silently." The
 * product decision has now been made, and this is that fuller fix. Filtering
 * rows could hide another office's words; it could never stop an applicant
 * ADDRESSING an office that has nothing to do with their permit, because the
 * message was not addressed to anyone.
 *
 * ── Why nullable-then-backfilled, and not NOT NULL ───────────────────────────
 *
 * `database.sqlite` holds real tester filings: 520 threads and 2,094 messages
 * that people actually wrote. Adding a NOT NULL column to an existing SQLite
 * table means rebuilding it — create, copy, drop, rename — for rows that must
 * not be lost. The column is added nullable, every existing row is given a
 * value in the same migration, and the invariant is held above the schema by
 * MessageThread::booted(), which stamps BPLO on any thread created without a
 * department. Nothing here drops a table or deletes a row.
 *
 * ── Why the 520 existing threads become BPLO's ───────────────────────────────
 *
 * They have no addressee to recover — that is the whole defect — so this is an
 * assumption, and it is recorded as one. BPLO is the coordinating office on
 * every filing: it issues the mayor's permit and cannot do so until every other
 * office has cleared its part, and it already reads the entire register through
 * `application.view_any_office`. Attributing an unaddressed historical thread
 * to BPLO therefore puts it in front of the one office that could already see
 * it, and in front of nobody else. Any other choice — the first assigned office,
 * the office of whoever spoke last — would hand a transcript to an office that
 * may never have been meant to read it, which is the exact defect (items 56 and
 * 111) this codebase has already had to fix twice.
 *
 * The cost is visible and accepted: a historical turn written by, say, the fire
 * inspector now lives in a BPLO thread, so the fire office no longer sees its
 * own old words in the applicant's inbox. That is the fail-closed direction. A
 * message is never shown to an office that should not have it; at worst an
 * office loses sight of history it can still find on the review sheet.
 *
 * ── The unique index ─────────────────────────────────────────────────────────
 *
 * `application_id` unique becomes `(application_id, department_id)` unique: one
 * conversation per office per filing, still exactly one row for any given pair,
 * so `firstOrCreate` stays race-safe. Dropping the old index touches no data —
 * it is an index, not a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            /*
             * `restrictOnDelete`: a department is a fixture of the LGU, not a
             * row anybody removes, and if one ever were removed, taking its
             * conversations down with it would destroy correspondence. Refusing
             * the delete is the honest failure.
             */
            $table->foreignId('department_id')
                ->nullable()
                ->after('application_id')
                ->constrained()
                ->restrictOnDelete();
        });

        /*
         * The backfill. Guarded on BPLO actually existing so a database seeded
         * without departments (none exists, but the guard costs nothing) leaves
         * the column null rather than writing a wrong id — and the model's
         * default then keeps new rows correct regardless.
         */
        $bplo = DB::table('departments')->where('code', 'BPLO')->value('id');
        if ($bplo !== null) {
            DB::table('message_threads')
                ->whereNull('department_id')
                ->update(['department_id' => $bplo]);
        }

        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropUnique('message_threads_application_id_unique');
            $table->unique(['application_id', 'department_id']);
        });
    }

    /**
     * Reversible only while no filing has yet been messaged by two offices.
     * Once it has, restoring the `application_id` unique index is impossible
     * without deleting somebody's conversation, and this migration will not do
     * that silently — it lets the index creation fail loudly instead.
     */
    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropUnique(['application_id', 'department_id']);
            $table->dropConstrainedForeignId('department_id');
            $table->unique('application_id');
        });
    }
};
