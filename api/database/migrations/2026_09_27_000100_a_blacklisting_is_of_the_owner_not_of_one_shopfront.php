<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A blacklisting belongs to the person, not to one of their shopfronts.
 *
 * ── What was wrong with holding it on the business ──────────────────────────
 *
 * `businesses.status` carried all four statuses, so blacklisting was a fact
 * about a shopfront. An owner blacklisted for, say, falsified documents could
 * therefore file freely for the other two businesses registered to the same
 * account — and register a fourth the same afternoon — because
 * `isBlockedFromApplying` reads one business's own column and nothing else.
 * The sanction was a door locked on a building with three other doors.
 *
 * Active, Flagged and Suspended stay where they are, and rightly: a suspension
 * is about a premises that failed an inspection, and a flag is a note to watch
 * one. Those are facts about a business. Blacklisting is a judgement about the
 * person filing, so it is recorded against the person
 * [client, 27 September 2026: *"once na naka blacklist, mismong owner na tlga
 * yan, bale lahat lahat ng business nya ay blacklisted na"*].
 *
 * ── Why the businesses keep a blacklisted status of their own too ───────────
 *
 * They are set to `blacklisted` in the same act, so every screen that reads a
 * business's status — the roster, the certificate, the QR check at the counter
 * — keeps working without having to learn about owners. This column is the
 * CAUSE and the business column is its consequence; reinstating the owner
 * lifts both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * Nullable and dated rather than a boolean: "when" is the question
             * asked of a sanction after the fact, and a null is the ordinary
             * state of every account in the register.
             */
            $table->timestamp('blacklisted_at')->nullable()->after('is_active');

            // Kept beside the flag rather than only in the audit trail, because
            // the owner is shown it and the roster prints it; an admin should
            // not have to open the log to answer "why is this person barred?".
            $table->string('blacklist_reason', 1000)->nullable()->after('blacklisted_at');

            // Who decided. A sanction with no name against it is the one kind
            // of record an auditor cannot follow up.
            $table->foreignId('blacklisted_by')->nullable()->after('blacklist_reason')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // The owner roster filters on it, and it is the sparse case.
            $table->index('blacklisted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['blacklisted_at']);
            $table->dropConstrainedForeignId('blacklisted_by');
            $table->dropColumn(['blacklisted_at', 'blacklist_reason']);
        });
    }
};
