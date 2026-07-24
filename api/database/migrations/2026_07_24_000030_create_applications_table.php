<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_id')->nullable()->unique();   // BIZ-YYYY-NNNNN (on submit)
            $table->foreignId('business_id')->constrained();
            $table->foreignId('applicant_user_id')->constrained('users');
            $table->string('application_type');                    // new | renewal | amendment
            $table->string('status')->default('draft');
            $table->foreignId('prior_permit_id')->nullable();      // renewals/amendments
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('deadline_at')->nullable();          // RA 11032 working-day deadline
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        // Permit types requested within a single unified application (M:N).
        Schema::create('application_permit_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permit_type_id')->constrained();
            $table->timestamps();
            $table->unique(['application_id', 'permit_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_permit_types');
        Schema::dropIfExists('applications');
    }
};
