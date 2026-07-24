<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('psic_code_id')->constrained();
            $table->decimal('capitalization', 14, 2)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'psic_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_lines');
    }
};
