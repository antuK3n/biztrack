<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved set-ups for the Custom Report builder (client, 19 October 2026:
 * "reports generation where the admin can select specific information").
 *
 * A row is one reader's named set-up: what to count, which numbers, which
 * filters and which breakdown, as the JSON App\Support\CustomReports
 * validates. It holds no figures — re-running a saved report counts the
 * register as it stands then.
 *
 * PER USER, not shared within the office. A set-up is a working tool, like a
 * bookmark: one clerk renaming or deleting "My barangay list" must not take it
 * from a colleague, and nothing here is a record anyone else relies on. If the
 * office later wants shared set-ups, add a nullable `department_id` and read
 * "mine or my office's" — the definition format does not change.
 *
 * The office the report is run for is NOT stored. It is decided on every run
 * by App\Support\AnalyticsOffice, so a saved set-up can never widen what its
 * reader may see.
 *
 * To undo: `down()` drops the table; nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('definition');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
