<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an amendment actually asks to change, field by field.
 *
 * ── Why the existing columns could not carry this ──────────────────────────
 *
 * `applications.amendment_ownership`, `_location`, `_nature` and `_other`
 * already exist, and they are NOT this. They are Section A of the RENEWAL form
 * (MCG-BPLO-FO-002) — four booleans answering "do you have any changes since
 * last year", which is a declaration, not a request. They say an address
 * changed. They cannot say what it changed TO.
 *
 * The standalone Amendment Form is the request, and the LGU's own paper has a
 * blank beside every box for exactly that: "CHANGE OF ADDRESS: (New Address)
 * ____". Without somewhere to put the new value, an amendment can only ever be
 * a note asking an officer to retype the register by hand — which is how the
 * filing and the register come to disagree, with nothing recording which was
 * meant.
 *
 * ── One row per field, not one JSON blob ──────────────────────────────────
 *
 * A blob would have been fewer lines here and worse everywhere else. Per-field
 * rows give three things the flow already needs:
 *
 *  - BPLO can RETURN one requested change and accept the rest, using the same
 *    `remarks_target` pointer the clearance return uses.
 *  - `old_value` is captured at the moment of approval, so the register keeps
 *    what it was as well as what it became. A blob of new values alone cannot
 *    answer "what was the floor area before this amendment".
 *  - `applied_at` per row makes a partial application visible instead of
 *    guessable, which matters because some kinds (ownership, lines) are not
 *    applied by the system at all yet.
 *
 * The unique index is the point of the pair: one request per field per filing.
 * Asking twice for the same field in one amendment is a contradiction, not two
 * requests, and the second write should replace the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('application_amendments')) {
            return;
        }

        Schema::create('application_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            /*
             * The business attribute being changed, from
             * `App\Support\AmendableFields::KINDS`. A string rather than an
             * enum column: the set is a product decision that will grow as the
             * remaining kinds on the paper are built, and every write goes
             * through that whitelist, so a value outside it cannot be stored by
             * any route the app exposes.
             */
            $table->string('field');

            /*
             * Both sides as TEXT, deliberately untyped.
             *
             * The fields this carries are a mix — a trade name is a string, a
             * floor area is a decimal, an employee count is an integer — and a
             * column per type would either need seven columns or a cast nobody
             * can see. They are captured as the register's own string form and
             * cast back by the field's own writer, which is the one place that
             * knows what type it is.
             *
             * `old_value` is null until approval: nothing is recorded at
             * request time, because what matters is what the value was when the
             * change was APPLIED, not when it was asked for. A business whose
             * area was corrected in between would otherwise have an amendment
             * claiming it overwrote a figure that was already gone.
             */
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_amendments');
    }
};
