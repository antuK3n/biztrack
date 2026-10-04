<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which officer held the filing when a message was written.
 *
 * ── The three things the client asked for, and why one column serves all ──
 *
 * "Kung ano lang ang naka assign sa kanya, yun lang ang pwede nyang ma-chat.
 * Once na in-unassign na, magsstay pa rin ang convo pero di nya na ma-cha-chat,
 * like for viewing na lang. So sa end ng business owner, yung unang officer,
 * pag may chat na, andon pa rin ang chat — kung mapapalitan naman ang officer,
 * don pa rin sa convo nya, at mapapalitan lang ang name. Pero sa end naman ng
 * bagong officer in charge, wala yung dating convo — bagong convo na dapat
 * nila" [client, 30 September 2026].
 *
 * So the applicant has ONE conversation per office that runs the whole length
 * of the filing, and each officer has their own stretch of it. That is a
 * property of the MESSAGE — who was holding the case when it was written — and
 * not of the thread, which is shared, nor of the assignment, whose
 * `officer_user_id` is overwritten in place and so remembers only the present.
 *
 * ── Why not date it from the assignment instead ──────────────────────────
 *
 * The obvious cheaper trick is to show an officer the messages written since
 * they took over, reading the boundary off `application_assignments`. It works
 * for the officer holding the case and fails for everybody else: once the row
 * is reassigned there is no record of where the previous officer's stretch
 * began or ended, so the officer who handed the case on could no longer read
 * the conversation they had — which is precisely the "for viewing na lang"
 * this is meant to preserve.
 *
 * ── The backfill, and what it cannot know ────────────────────────────────
 *
 * Existing messages are stamped with whoever holds the filing for that office
 * today. That is right for every case that has never changed hands, which is
 * all of them in the register today, and approximate for any that has: a
 * handover before this migration left no trace to reconstruct, so the whole
 * conversation is attributed to the current holder rather than being split at
 * a date nobody recorded.
 *
 * Attributing it forward is the safer of the two errors. The alternative —
 * leaving it null — would hide the existing correspondence from the officer
 * currently working the case, which is the one person who needs it.
 *
 * Null stays meaningful afterwards: an office with nobody holding the filing
 * can still be written to, and those messages belong to the office rather than
 * to a person. Whoever picks the case up reads them, because nobody else's
 * stretch has claimed them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            /*
             * `nullSafe` by construction: nothing cascades from here. An
             * officer who leaves the LGU keeps their name on the messages they
             * wrote - the audit trail is the point - and `nullOnDelete` would
             * quietly hand their stretch of every conversation to whoever
             * comes next.
             */
            $table->foreignId('handled_by_user_id')
                ->nullable()
                ->after('sender_user_id')
                ->constrained('users');

            // Every officer read of a thread filters on this, per application.
            $table->index(['thread_id', 'handled_by_user_id'], 'messages_thread_handler_index');
        });

        /*
         * One statement per department rather than per message: the register
         * holds a few hundred filing messages today and will hold more, and a
         * per-row update would be a query each.
         *
         * Only messages on a FILING thread are stamped. A general enquiry and
         * the administrator's line have no officer in charge - there is no
         * filing to hold - and their `handled_by_user_id` stays null.
         */
        DB::statement(<<<'SQL'
            UPDATE messages
               SET handled_by_user_id = (
                     SELECT a.officer_user_id
                       FROM message_threads t
                       JOIN application_assignments a
                         ON a.application_id = t.application_id
                        AND a.department_id  = t.department_id
                      WHERE t.id = messages.thread_id
                      LIMIT 1
                   )
             WHERE EXISTS (
                     SELECT 1
                       FROM message_threads t
                      WHERE t.id = messages.thread_id
                        AND t.application_id IS NOT NULL
                   )
        SQL);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_thread_handler_index');
            $table->dropConstrainedForeignId('handled_by_user_id');
        });
    }
};
