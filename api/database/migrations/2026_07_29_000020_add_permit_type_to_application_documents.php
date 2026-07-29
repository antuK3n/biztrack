<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklist item 59 — "upload a permit you already hold".
 *
 * An applicant who already holds, say, a valid sanitary permit should attach
 * the certificate instead of filing a fresh application with the City Health
 * Office. Such an attachment is not a generic requirement: it stands in for a
 * whole clearance, so the row has to say which clearance it stands in for.
 * A null permit_type_id is the ordinary case (a plain documentary requirement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->foreignId('permit_type_id')
                ->nullable()
                ->after('document_type_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('permit_type_id');
        });
    }
};
