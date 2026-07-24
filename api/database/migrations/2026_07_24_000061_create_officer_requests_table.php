<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Paper Table 58 shape. Supports document | message | meeting requests.
    public function up(): void
    {
        Schema::create('officer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users');   // was created_by_user_id
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('title');                        // was `subject`
            $table->text('description')->nullable();        // was `body`
            $table->string('request_type')->default('document'); // document | message | meeting
            $table->string('status')->default('pending');   // pending|submitted|fulfilled|rejected
            $table->timestamp('due_date')->nullable();
            // document requests
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            // impl linkage to the uploaded ApplicationDocument (kept from v2 build)
            $table->foreignId('application_document_id')->nullable()
                ->constrained('application_documents')->nullOnDelete();
            // meeting requests (officer-provided fields; calendar integration is future work)
            $table->timestamp('meeting_scheduled_at')->nullable();
            $table->integer('meeting_duration_minutes')->default(30);
            $table->string('meeting_link')->nullable();
            $table->string('meeting_platform')->default('google_meet');
            $table->string('external_calendar_event_id')->nullable();
            // applicant response
            $table->text('applicant_response')->nullable();  // was response_body
            $table->timestamp('submitted_at')->nullable();   // was responded_at (applicant side)
            // officer review
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('officer_requests');
    }
};
