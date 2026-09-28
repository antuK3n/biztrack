<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third kind of conversation: an office account and the System Administrator.
 *
 * ── Why it could not be told apart by its columns ───────────────────────────
 *
 * The two existing kinds are distinguished by which columns are set — a filing
 * thread has `application_id`, a general enquiry has `user_id` and
 * `department_id`. The obvious third combination is a `user_id` with no
 * department, since the super admin belongs to no office.
 *
 * That does not work, and the reason it does not is itself deliberate:
 * `MessageThread::booted()` fills a null `department_id` with BPLO on the way
 * in, so that no thread can exist that nobody is answerable for. A new kind
 * would have been silently turned into a BPLO enquiry — and then collided with
 * the real one on `(user_id, department_id)`.
 *
 * So the kind is stated rather than inferred. `kind` is null on both existing
 * shapes and `'admin'` on this one, which leaves every current row and every
 * current query untouched.
 *
 * ── The index ───────────────────────────────────────────────────────────────
 *
 * `(user_id, kind)` unique. One administrator conversation per office account,
 * enforced rather than merely intended, so two requests racing to open the same
 * one cannot leave a second behind. Rows where `kind` is null — every filing
 * and every enquiry — do not collide with each other, because SQL treats NULLs
 * in a unique index as distinct; their own uniqueness is already carried by the
 * two indexes that were here before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('status');
            $table->unique(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
