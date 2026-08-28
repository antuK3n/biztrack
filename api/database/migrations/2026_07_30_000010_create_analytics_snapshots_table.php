<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where computed statistics are kept between refreshes.
 *
 * The analytics architecture is batch: `analytics:refresh` queries the register,
 * computes the statistics and lands the result here. Page loads read this table
 * rather than recomputing, which is what keeps an analytics screen fast.
 *
 * NOTE ON HISTORY — this table was built for an R (plumber) statistics service
 * that Laravel pushed row sets to. R has been removed and the statistics are now
 * computed in PHP by app/Support. The table itself is unchanged and deliberately
 * so: nothing about batching, keying by window, or recording `computed_at`
 * followed from the engine being remote, so none of it needed undoing. Two
 * columns are now vestigial rather than wrong, and are annotated below. There is
 * no migration to drop them — they carry a true record of how the rows that
 * predate the removal were produced, and rebuilding a table to delete a nullable
 * column nobody reads is a risk taken for nothing.
 *
 * One row per (dataset, parameter combination), because statistics are not
 * sliceable — control limits fitted on a 52-week window are not the limits for
 * the 26-week view, so each window is its own snapshot. `key` carries the
 * parameters ("processing_time:weeks=52") and is what a read looks up by.
 *
 * `computed_at` is the point of the table as much as `payload` is. Every screen
 * has to say when its figures were computed: a tester's brand-new application
 * legitimately will not appear until the next refresh, and the UI must not imply
 * otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();

            // "processing_time:weeks=52" — dataset plus its parameters.
            $table->string('key', 191)->unique();

            // Denormalised out of the key so `--only=` and pruning can filter
            // without parsing it.
            $table->string('dataset', 64)->index();

            // The statistics, in exactly the shape the endpoint serves.
            $table->json('payload');

            /*
             * How the row was produced. Rows written before R was removed say
             * 'r'; everything written since says 'local'. The default is the
             * historical one and is now unreachable — AnalyticsRefresher is the
             * only writer and always sets this explicitly — so it is left alone
             * rather than changed by a table rebuild that would buy nothing.
             *
             * Nothing reads this column. What a response reports as `source` is
             * 'snapshot' vs 'local', which answers a different question: whether
             * the figures came from a refresh or were computed for that request.
             * AnalyticsResolver decides that from whether a row was found here.
             */
            $table->string('source', 16)->default('r');

            /*
             * R's version string ("4.6.1") on rows R computed, and null on
             * everything since. Kept because it is a true record of those rows,
             * and because a single implementation that ships with the code that
             * reads it has no version worth recording separately.
             */
            $table->string('engine_version', 64)->nullable();

            // Wall-clock for the computation, to catch a dataset growing towards
            // a slow refresh before it becomes a problem.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};
