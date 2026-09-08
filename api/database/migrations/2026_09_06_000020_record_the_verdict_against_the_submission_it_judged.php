<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which submission was rejected, and why.
 *
 * The verdict lived only on the parent requirement — one `status` and one
 * `remarks` — so it was always the verdict on the LATEST submission and the
 * earlier ones kept no record of what happened to them. After a resubmission
 * the history read "Submission #1, Submission #2" with a single remark floating
 * above both, attached to neither.
 *
 * That matters as soon as a requirement goes round more than once, which is the
 * normal case for a document that needs a clearer scan: the applicant has to be
 * able to see that #1 was refused for one reason and #2 for another, rather
 * than a single sentence that changes under them each time an officer rules.
 *
 * Nullable throughout. Existing responses have no verdict recorded, which is
 * the truth: nothing was ever written down per submission until now, and
 * inventing one from the parent's current status would date every past
 * submission with today's outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('officer_request_responses', function (Blueprint $table) {
            // fulfilled | needs_resubmission | rejected — the OfficerRequestStatus
            // the office moved the requirement to when it ruled on this reply.
            $table->string('review_outcome', 40)->nullable()->after('file_path');
            $table->text('review_remarks')->nullable()->after('review_outcome');
            $table->timestamp('reviewed_at')->nullable()->after('review_remarks');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('officer_request_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['review_outcome', 'review_remarks', 'reviewed_at']);
        });
    }
};
