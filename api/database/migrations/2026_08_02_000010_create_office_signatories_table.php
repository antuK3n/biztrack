<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The people who sign each office's forms.
 *
 * The LGU application forms carry pre-printed names in their signature blocks —
 * the CENRO form names its evaluator and its office chief. Those names are not
 * part of the form design, they are the current officeholders, and officeholders
 * rotate. A name frozen into a scanned template or a PHP constant goes wrong
 * silently: the form keeps printing someone who left the post, and the only
 * route to a fix is a code change and a redeploy.
 *
 * So they live here, one row per (office, role), editable by anyone holding
 * reference.manage, and are rendered onto generated forms at output time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('role');          // "Evaluator", "Chief-CENRO" — as printed on the form
            $table->string('name');
            $table->integer('sort_order')->default(0);   // signature-block order on the page
            /*
             * Kept rather than deleted when someone leaves: an already-issued
             * permit still has to explain whose name is on it, and a hard delete
             * would break that. Only active rows are offered for new output.
             */
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One person per role per office. Re-seeding updates rather than duplicates.
            $table->unique(['department_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_signatories');
    }
};
