<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record itself, as it stood, beside every removal in the audit log.
 *
 * Ken's checklist, 27 September 2026, "Audit Log 1 — keep removed records".
 * Until now a removal wrote an action name and, at best, two or three fields
 * someone thought to pass in `changes`: `document.removed` carried nothing at
 * all, `application.draft_deleted` the type and the business id. Once the row
 * was gone, "what exactly was deleted?" had no answer anywhere in the register.
 *
 * `snapshot` holds the whole row — every column except the model's `$hidden`
 * ones, so no password hash or remember token — plus the child rows that went
 * with it (a draft's documents, a business's address). Written by
 * App\Support\Audit::removed() and by nothing else.
 *
 * A column of its own rather than a key inside `changes`, for two reasons:
 * "show me only removals" is then `snapshot IS NOT NULL`, which reads the same
 * on SQLite and PostgreSQL where a JSON-path filter does not; and `changes`
 * keeps meaning what it has always meant — what moved — so no existing reader
 * of it has to learn a new shape.
 *
 * Personal data under RA 10173, like the rows it copies. The audit screen is
 * `audit.view`, which only the super admin holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
