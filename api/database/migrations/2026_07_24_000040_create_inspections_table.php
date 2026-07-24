<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained();
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('scheduled');   // scheduled|rescheduled|in_progress|completed|cancelled
            $table->string('result')->nullable();             // passed|failed|conditional
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('conducted_at')->nullable();
            $table->text('findings')->nullable();
            $table->json('photo_paths')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
