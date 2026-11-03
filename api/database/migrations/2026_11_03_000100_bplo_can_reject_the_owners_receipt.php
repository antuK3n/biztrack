<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BPLO can reject the owner's counter receipt, and must give a reason.
 *
 * Ken's decision, 3 November 2026: Mark as paid waits for the owner's receipt
 * (PaymentController::counterPaymentNow), and the officer holding the filing
 * can refuse a receipt that does not hold up instead of marking the bill paid
 * against it. The owner is told why, in the bell and by e-mail, and sends a
 * new one from the Pay page.
 *
 *   payment_proofs.rejected_at          When BPLO refused it. A rejected proof
 *                                       is never pending again
 *                                       (PaymentProof::pendingFor), so Mark as
 *                                       paid waits for the next one.
 *   payment_proofs.rejected_by_user_id  The officer who refused it.
 *   payment_proofs.rejection_reason     What the officer typed, shown to the
 *                                       owner word for word. 500 characters,
 *                                       the endpoint's own limit.
 *
 * The row is kept, file and all, as a superseded one is: the history of what
 * the owner sent stays whole.
 *
 * A new migration rather than an edit to 2026_10_21_000100, which the
 * showcase database has already run. Additive only — three nullable columns —
 * so safe to run on its own against the live register (AGENTS.md §2.2).
 * WipeForDefense needs nothing: it lists `payment_proofs` as a table and
 * `file_path` as its file column, and neither changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->index();
            $table->string('rejection_reason', 500)->nullable();
        });

        /*
         * The key to `users` on PostgreSQL only, for the reason
         * 2026_10_21_000100 gives for `payments.recorded_by_user_id`: on
         * SQLite, adding a foreign key to an existing table rebuilds it, and
         * by this point of a fresh `migrate` the reporting views are back and
         * stop the rebuild. A removed account must not take the record of who
         * refused the receipt with it, hence `nullOnDelete`.
         */
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->foreign('rejected_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->dropForeign(['rejected_by_user_id']);
            });
        }

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropIndex(['rejected_by_user_id']);
            $table->dropColumn(['rejected_at', 'rejected_by_user_id', 'rejection_reason']);
        });
    }
};
