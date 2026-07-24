<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // UI prototype Parts 4-7 (pages 040-044): per-office application form payloads.
    // No manuscript equivalent — stored as opaque JSON keyed by permit type.
    public function up(): void
    {
        Schema::create('application_office_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permit_type_id')->constrained()->cascadeOnDelete();
            $table->json('form_data');
            $table->timestamps();
            $table->unique(['application_id', 'permit_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_office_forms');
    }
};
