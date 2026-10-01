<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One CURRENT holder per post per office, not one holder ever.
 *
 * The table was created with unique(department_id, role). Retiring a signatory
 * keeps the row — the record of who signed has to stay whole — so the retired
 * person went on holding the post for good. Adding their successor as
 * "Chief-CENRO" then failed on the constraint, and the only way through was to
 * type the new name over the retired row, which erases exactly the record
 * retiring exists to keep.
 *
 * The rule that is actually true is narrower: an office has one current
 * Chief-CENRO. So the index becomes partial, over active rows only. Retired rows
 * may share a post with each other and with whoever holds it now.
 *
 * Raw SQL because the schema builder has no partial index. `WHERE is_active`
 * reads the same on SQLite (an integer 0/1) and on PostgreSQL (a boolean), the
 * two engines this runs on (docs/postgres.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_signatories', function (Blueprint $table) {
            $table->dropUnique(['department_id', 'role']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX office_signatories_current_role_unique '
            .'ON office_signatories (department_id, role) WHERE is_active'
        );
    }

    /**
     * Back to one holder per post, ever. This refuses, rather than choosing a
     * row to drop, while a retired holder and a current one share a post — that
     * has to be reconciled by a person before it can run.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX office_signatories_current_role_unique');

        Schema::table('office_signatories', function (Blueprint $table) {
            $table->unique(['department_id', 'role']);
        });
    }
};
