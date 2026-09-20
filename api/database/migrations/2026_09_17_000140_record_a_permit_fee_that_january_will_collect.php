<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Permit fees incurred outside the January season, waiting to be billed.
 *
 * ── Why this table has to exist ───────────────────────────────────────────
 *
 * Client's decision, 17 September 2026: *"The payment for each permit will also
 * happen ONLY WHEN a business permit was renewed on January. So for example, if
 * I renew my sanitary permit, its payment will only reflect once I renew my
 * business permit for the next renewal season."*
 *
 * Nothing in this system could express that. A fee lives on
 * `fee_assessments`, which is keyed one-per-APPLICATION and is what the
 * applicant pays to move that filing forward — so a sanitary renewal in June
 * either has a payable assessment (and the applicant is billed in June, which
 * is what the decision forbids) or has none at all (and the money is simply
 * lost). Neither is a receivable.
 *
 * So the amount is recorded against the BUSINESS at the moment the permit is
 * issued, and the next business-permit renewal's Tax Order of Payment sweeps in
 * everything still unbilled.
 *
 * ── The column that makes it a ledger rather than a flag ──────────────────
 *
 * `billed_on_application_id` is what a row is settled BY, not a boolean. It
 * means an auditor can answer "which January collected this June fee", which is
 * the question an LGU actually asks of a deferred charge — and it means
 * re-running an assessment cannot double-bill, because a row with that column
 * set is already spoken for.
 *
 * `amount` is frozen at issue. Whether a deferred clearance should be priced at
 * the rates in force when it was issued or when it is billed is a policy
 * question BPLO has not been asked (recorded in
 * docs/renewal-2026-09-17.md); storing the amount answers it as "at issue",
 * which is the reading that does not change a figure under somebody after the
 * fact. If BPLO says otherwise this column becomes a record of what it would
 * have cost, and the sweep recalculates.
 *
 * ── No surcharge, deliberately ────────────────────────────────────────────
 *
 * There is no penalty column and no due date. Sec. 2N's surcharge applies to a
 * renewal filed late; whether it applies to a fee the LGU itself chose to defer
 * is BPLO's reading to give. Until they do, an unbilled fee simply waits — which
 * is also the client's decision: *"They wait indefinitely, and are visible."*
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unbilled_permit_fees', function (Blueprint $table) {
            $table->id();

            /*
             * The receivable is the BUSINESS's, not the filing's. That is the
             * whole point: the filing that incurred it is finished and paid
             * nothing, and the filing that will collect it does not exist yet.
             */
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            /* Which filing incurred it, for the audit trail. */
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permit_type_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);

            /* When the permit was issued — what "unbilled since" counts from. */
            $table->timestamp('incurred_at');

            /*
             * Null until a January renewal collects it. Nullable on purpose
             * rather than a separate settled table: one row, two states, and no
             * chance of a fee existing in both places or neither.
             */
            $table->foreignId('billed_on_application_id')->nullable()
                ->constrained('applications')->nullOnDelete();
            $table->timestamp('billed_at')->nullable();

            $table->timestamps();

            /*
             * The one query this table exists to answer: what does this business
             * still owe? Composite, business first, because every read is scoped
             * to a business before it is scoped to a state.
             */
            $table->index(['business_id', 'billed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unbilled_permit_fees');
    }
};
