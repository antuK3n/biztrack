<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the statistics R computed are kept between refreshes.
 *
 * The analytics architecture is batch: `analytics:refresh` pushes register rows
 * to the R service, R computes, and the result lands here. Page loads read this
 * table and never call R, which is what keeps an analytics screen fast and keeps
 * an R outage from breaking one.
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

            // The statistics, in exactly the shape the endpoint serves. R and the
            // PHP fallback produce the same schema so a screen cannot tell them
            // apart by shape — only by the meta that says which one ran.
            $table->json('payload');

            // 'r' today. The column exists so a snapshot can never be mistaken
            // for R output if we ever persist a locally computed one.
            $table->string('source', 16)->default('r');

            // R's version string, recorded per snapshot: if two snapshots
            // disagree, the first question is whether the engine changed.
            $table->string('engine_version', 64)->nullable();

            // Wall-clock for the push+compute round trip, to catch a dataset
            // growing towards the refresh timeout before it starts failing.
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
