<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();               // BUSINESS, SANITARY, FSIC
            $table->string('name');
            $table->string('permit_number_prefix');         // MCB, MCS, MCF (impl delta)
            // paper Table 30 name; FK to departments (was `department_id`)
            $table->foreignId('issuing_department_id')->constrained('departments');
            $table->integer('validity_days')->default(365); // paper Table 30
            $table->text('description')->nullable();         // paper Table 30
            $table->boolean('requires_inspection')->default(false);
            $table->decimal('base_fee', 10, 2)->default(0);
            $table->decimal('per_line_surcharge', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_types');
    }
};
