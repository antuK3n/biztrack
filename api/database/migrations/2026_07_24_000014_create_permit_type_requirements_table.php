<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_type_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            // paper Table 59: context + is_mandatory (was `is_required`) + notes
            $table->string('context')->default('all'); // all|new|renewal|occupancy|business_permit
            $table->boolean('is_mandatory')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['permit_type_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_type_requirements');
    }
};
