<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A question you can ask before you have anything to ask about.
 *
 * `message_threads` was keyed on a filing — `application_id` NOT NULL — so the
 * only way to reach an office was to have already filed. Everyone who has not
 * filed yet had no way in at all, and the app tells them to use it anyway:
 * "Your email is your sign-in ID and can't be changed here. Contact the City
 * BPLO to update it." There was no contact to make. The people most likely to
 * need an answer — someone deciding whether to apply, someone locked out of
 * their own email — were exactly the people the schema shut out.
 *
 * ## What a general thread is
 *
 * `application_id` null, `user_id` set: a conversation that belongs to a PERSON
 * rather than to a filing. Everything else about it is an ordinary thread —
 * same messages table, same attachments, same notifications — because a
 * separate pair of tables would be two of everything to keep in step, and the
 * only real difference is what the conversation is about.
 *
 * BPLO only, and the model's existing default already says why: BPLO
 * coordinates every filing and "is who an applicant writes to when they do not
 * know which office to ask". Without a filing there are no assignments, so no
 * other office has any routing to reason about; addressing one would hand it
 * mail about a permit that does not exist. `(user_id, department_id)` unique
 * keeps it to one conversation per person per office, the same shape
 * `(application_id, department_id)` gives a filing.
 *
 * ## Relaxing NOT NULL rebuilds this table, and that is not done lightly
 *
 * SQLite cannot drop a NOT NULL in place: Laravel creates a new table, copies
 * every row, drops the old one and renames. This table holds correspondence
 * people actually wrote — the migration that added `department_id` counted 520
 * threads and 2,094 messages — and it deliberately avoided a rebuild by adding
 * its column nullable.
 *
 * There is no such dodge here. A thread without a filing is precisely a null
 * `application_id`, so the constraint is the thing that has to go. What makes
 * it safe is that the copy is all-or-nothing inside the migration's
 * transaction, and that the row count is asserted below rather than assumed: if
 * a single thread failed to survive, this throws and rolls back instead of
 * reporting success. "Nothing was lost" is a measurement.
 *
 * Nothing is deleted, no column is dropped, and the existing
 * `(application_id, department_id)` unique index is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = DB::table('message_threads')->count();

        Schema::table('message_threads', function (Blueprint $table) {
            /*
             * The person a general thread belongs to. `restrictOnDelete` for
             * the same reason `department_id` uses it: a conversation must not
             * be destroyed as a side effect of removing something else. Users
             * are soft-deleted here anyway, so this refuses only a force
             * delete, which is the honest failure.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->after('application_id')
                ->constrained()
                ->restrictOnDelete();
        });

        // The rebuild. Nullable only — nothing else about the column changes.
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->change();
        });

        $after = DB::table('message_threads')->count();

        if ($before !== $after) {
            throw new RuntimeException(
                "message_threads lost rows in the rebuild: {$before} before, {$after} after. Rolled back."
            );
        }

        Schema::table('message_threads', function (Blueprint $table) {
            // One general conversation per person per office. Application
            // threads leave user_id null, and SQLite treats nulls as distinct,
            // so this constrains general threads only.
            $table->unique(['user_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'department_id']);
            $table->dropConstrainedForeignId('user_id');
        });

        /*
         * `application_id` is deliberately left nullable.
         *
         * Restoring NOT NULL would fail on any general thread that exists, and
         * the only way to make it succeed would be to delete somebody's
         * conversation. This migration will not do that silently — a column
         * that permits null where nothing writes null costs nothing.
         */
    }
};
