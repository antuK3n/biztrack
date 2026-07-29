<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * One "Other Requirement" often needs several uploads or a follow-up note,
     * so responses move off officer_requests into their own child table. The
     * parent's applicant_response / submitted_at / file_* / application_document_id
     * columns stay as a mirror of the LATEST response so mobile and the v2
     * contract (response_body, responded_at) keep working.
     */
    public function up(): void
    {
        Schema::create('officer_request_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('officer_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');   // author (the applicant)
            $table->text('body')->nullable();                     // nullable: a file-only reply is valid
            $table->foreignId('application_document_id')->nullable()
                ->constrained('application_documents')->nullOnDelete();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
            $table->index(['officer_request_id', 'created_at']);
        });

        // Carry existing single responses over so live tester data survives.
        $existing = DB::table('officer_requests')
            ->join('applications', 'applications.id', '=', 'officer_requests.application_id')
            ->whereNotNull('officer_requests.submitted_at')
            ->select([
                'officer_requests.id',
                'officer_requests.applicant_response',
                'officer_requests.application_document_id',
                'officer_requests.file_name',
                'officer_requests.file_path',
                'officer_requests.submitted_at',
                'applications.applicant_user_id',
            ])
            ->get();

        foreach ($existing as $row) {
            if ($row->applicant_user_id === null) {
                continue;   // orphaned application; nothing sensible to attribute the reply to
            }
            DB::table('officer_request_responses')->insert([
                'officer_request_id' => $row->id,
                'user_id' => $row->applicant_user_id,
                'body' => $row->applicant_response,
                'application_document_id' => $row->application_document_id,
                'file_name' => $row->file_name,
                'file_path' => $row->file_path,
                'created_at' => $row->submitted_at,
                'updated_at' => $row->submitted_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('officer_request_responses');
    }
};
