<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A draft needs a name of its own (tester checklist item 36).
 *
 * Until now the wizard header and the Drafts page both showed the business
 * name, so an owner filing three permits for one business saw three
 * identical cards. The title is the applicant's own label for the filing;
 * it stays nullable and every reader falls back to the business name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('title', 120)->nullable()->after('application_type');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
