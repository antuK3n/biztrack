<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ── Somewhere to put a filing that is not yet a filing ───────────────────────
 *
 * The wizard cannot create its draft until the API will accept a business, and
 * `BusinessController::store` requires a name, a form of organization, a
 * registration number, a barangay and at least one line of business. Those
 * answers are spread across the wizard's second and third steps, so the
 * sequence in practice is:
 *
 *   privacy      typed, saved nowhere
 *   address      typed, saved nowhere   (Location & Zoning, ~8 answers)
 *   business     typed, saved nowhere until the step is LEFT   (~15 answers)
 *   operation    the draft now exists; everything autosaves
 *
 * Roughly twenty-five answers live only in the tab until the applicant
 * finishes step three. A refresh, a crash or a closed laptop lost all of it,
 * and the sessionStorage backup added on 28 September 2026 is a tab-local
 * consolation prize: it cannot survive the tab, cannot be opened on another
 * device, and is not in the drafts list — which is exactly what its own banner
 * has to admit. Client, 29 September 2026: *"is there a way for the auto-save
 * in drafts to work quickly? ... Even in Data Privacy part, it should be saved
 * now in the draft quickly."*
 *
 * ── Why a scratch table and not a laxer `businesses` ─────────────────────────
 *
 * The obvious alternative is to let a business be created with just a name and
 * enforce the rest at submission. That buys the same result by putting
 * half-answered rows into the register the whole system reads — the permit
 * register, the renewal prefill, the officer's queue — and every one of those
 * would need to learn to skip them. The validation on `businesses` is not
 * ceremony; it is what makes a row there mean something.
 *
 * So the unfinished answers go somewhere that means nothing: one row per user
 * per application type, holding the wizard's own form state as JSON. Nothing
 * reads it but the wizard that wrote it. The moment the answers are complete
 * enough for a real draft, the wizard creates one and this row is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wizard_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            /*
             * `new`, `renewal` or `amendment`. Part of the key because the
             * three are different forms and an applicant may reasonably have
             * one of each in progress; not validated against an enum here,
             * because this table stores what the wizard is holding rather than
             * anything the register acts on.
             */
            $table->string('application_type', 32);
            /* What the drafts list shows. The wizard's own title box. */
            $table->string('title')->nullable();
            /*
             * The wizard's form state, verbatim. Deliberately unshaped: this
             * is a resume point for one screen, and giving it columns would
             * mean migrating the table every time the form gains a question.
             */
            $table->json('payload');
            $table->timestamps();

            $table->unique(['user_id', 'application_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wizard_drafts');
    }
};
