<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->boolean('is_checked')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_checks');
    }
};
