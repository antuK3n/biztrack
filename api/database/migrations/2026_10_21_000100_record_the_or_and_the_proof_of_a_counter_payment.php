<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An over-the-counter payment keeps its Official Receipt and its proof
 * (thesis panel revisions, Tool Defense matrix items 1, 4, 5 and 6).
 *
 * Until now BPLO's Mark as paid took nothing from the request [Ken, 4 October
 * 2026: "no need to enter anything"], so a counter payment was a row saying a
 * bill was settled and nothing about the receipt the City Treasurer issued
 * for it. The panel asked for the opposite: the OR number and the date it was
 * paid recorded against the payment, who recorded it, and whatever the owner
 * or the officer had as proof.
 *
 *   payments.or_number            The Official Receipt's number, as typed by
 *                                 the officer. Indexed: the OR Records screen
 *                                 searches by it. NOT unique — nobody has said
 *                                 a duplicate must be refused, and a typo
 *                                 refused at the counter is a bill left unpaid.
 *   payments.or_date              The day the owner paid at the counter, which
 *                                 can be earlier than the day BPLO records it
 *                                 (`paid_at` stays the recording, because the
 *                                 workflow's clocks run from it).
 *   payments.recorded_by_user_id  The officer who pressed Mark as paid.
 *
 *   payment_proofs                Every piece of proof, one row each, never
 *                                 overwritten: what the owner sent from the Pay
 *                                 page (an OR number and date, a photo, or
 *                                 both), and the photo an officer attaches when
 *                                 marking the bill paid. `payment_id` is filled
 *                                 when BPLO records the payment the proof is
 *                                 for. An owner who sends again before that
 *                                 replaces the pending proof — the old row gets
 *                                 `superseded_at` and stays, with its file and
 *                                 its audit row, so the history is whole.
 *
 * Additive only — three nullable columns on `payments` and a new table — so
 * safe to run on its own against the live register (AGENTS.md §2.2).
 * WipeForDefense names the new table, as it must for every table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('or_number', 30)->nullable()->index();
            $table->date('or_date')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->index();
        });

        /*
         * The key to `users` on PostgreSQL only, where the register lives.
         *
         * On SQLite, adding a foreign key to an existing table rebuilds it, and
         * by this point of a fresh `migrate` the reporting views are back
         * (2026_09_27_000130) and stop the rebuild with "error in view
         * report_payments" — ReportViews steps them aside only around a run
         * that starts with them in place. Measured on the test suite's fresh
         * database. A removed account must not take the record of who took the
         * money with it, hence `nullOnDelete`.
         */
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('or_number', 30)->nullable();
            $table->date('or_date')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['recorded_by_user_id']);
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['or_number']);
            $table->dropIndex(['recorded_by_user_id']);
            $table->dropColumn(['or_number', 'or_date', 'recorded_by_user_id']);
        });
    }
};
