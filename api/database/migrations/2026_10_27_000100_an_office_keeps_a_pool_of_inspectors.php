<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An office keeps a pool of inspectors, each covering barangays and keeping
 * days [client, 27 October 2026: "pagsasamahin na sa isang inspector kung ano
 * ano ang magkakalapit na barangay … o kaya sa isang barangay lang … tas ayon
 * na ang inspector na mapipili"].
 *
 * ── Why a table of its own, not `users` ─────────────────────────────────────
 *
 * An inspector can have no account in the system (client, 5 October 2026 —
 * which is why `inspections.inspector_name` is typed text). A pool that only
 * held accounts would leave those inspectors out of the very feature meant
 * to assign them. So an inspector is a row here, in the office's own list,
 * with an OPTIONAL link to an account for the ones that have one.
 *
 * ── What the pool knows ─────────────────────────────────────────────────────
 *
 *   - `inspector_barangay`: the barangays each inspector covers — one, or a
 *     cluster of neighbours. The suggestion at booking starts from this.
 *   - `works_days`: which weekdays they go out (ISO 1–5), and
 *     `max_visits_per_day`: how many visits a day they can take.
 *   - `inspector_leaves`: dates they are away. A visit is never suggested on
 *     a day they are not working.
 *
 * `inspections.inspector_id` records which pool inspector took a visit;
 * `inspector_name` is still written beside it, so every screen that reads
 * the name keeps working and a visit assigned to somebody later removed
 * from the pool still says who went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('mobile_number', 20)->nullable();
            $table->boolean('is_active')->default(true);
            // ISO weekdays, 1 = Monday … 5 = Friday. The default is the office week.
            $table->json('works_days')->nullable();
            $table->unsignedTinyInteger('max_visits_per_day')->default(4);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['department_id', 'is_active']);
        });

        Schema::create('inspector_barangay', function (Blueprint $table) {
            $table->foreignId('inspector_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barangay_id')->constrained()->cascadeOnDelete();
            $table->primary(['inspector_id', 'barangay_id']);
        });

        Schema::create('inspector_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspector_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->index(['inspector_id', 'starts_on', 'ends_on']);
        });

        Schema::table('inspections', function (Blueprint $table) {
            $table->foreignId('inspector_id')->nullable()->after('inspector_user_id')
                ->constrained('inspectors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inspector_id');
        });
        Schema::dropIfExists('inspector_leaves');
        Schema::dropIfExists('inspector_barangay');
        Schema::dropIfExists('inspectors');
    }
};
