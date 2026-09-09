<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Other Requirement can be raised by the SYSTEM, not only by an officer.
 *
 * ── Why ───────────────────────────────────────────────────────────────────
 *
 * `officer_requests` models one office asking one applicant for one document,
 * with a due date, an upload and a review — which is exactly the shape of the
 * DENR permits a CEC leaves outstanding. MCG-CENRO-FO-001 gives the applicant
 * six months from issuance to comply, and the client's decision (9 September
 * 2026) is that the system raises those items automatically the moment CENRO
 * issues the certificate, rather than an officer remembering to.
 *
 * `requested_by_user_id` was NOT NULL and foreign-keyed to `users`, which is
 * right for the only case that existed until now — an officer typing a request.
 * Nobody types these. They are raised inside `grantClearance`, in the same
 * transaction that mints the permit, and there is no person behind them.
 *
 * NULL is the honest record of that. The alternatives were both worse:
 * attributing them to whichever inspector happened to record the passing visit
 * puts a name against a request that officer never composed, and inventing a
 * "system" user account puts a row in `users` that can never sign in and that
 * every account listing then has to special-case.
 *
 * Readers must treat null as "raised automatically", never as missing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('officer_requests', function (Blueprint $table) {
            $table->foreignId('requested_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Deliberately NOT reinstating NOT NULL.
         *
         * Rolling this back on a database that has raised any automatic request
         * would fail on those rows, or — worse, if the driver is lenient —
         * demand a user id for them and get whatever the rebuild supplies. A
         * down migration that can corrupt the data it is restoring is not a
         * safety net. Reversing this properly means deciding what those rows
         * should say, which is a decision and not a schema change.
         */
    }
};
