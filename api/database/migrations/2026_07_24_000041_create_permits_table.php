<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permits', function (Blueprint $table) {
            $table->id();
            $table->string('permit_number')->unique();          // MCB/MCS/MCF-YYYY-NNNNNN
            $table->foreignId('application_id')->constrained();
            $table->foreignId('business_id')->constrained();
            $table->foreignId('permit_type_id')->constrained();
            $table->string('status')->default('active');        // active|expired|revoked|suspended
            $table->date('valid_from');
            $table->date('valid_until');
            $table->string('pdf_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permits');
    }
};
