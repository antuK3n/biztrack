<?php

namespace App\Console\Commands;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;
use App\Support\DefenseAccounts\Playbook;
use App\Support\ManilaCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Give the counter payments made before the OR was asked for the Official
 * Receipt they would have carried.
 *
 * FOR GENERATED DEFENSE DATA ONLY. The OR numbers it writes are invented, not
 * read off a receipt. On a register holding real payments it would put a
 * receipt nobody issued against real money, so it is not to be run there.
 *
 * ── Why ─────────────────────────────────────────────────────────────────────
 *
 * The thesis panel's revisions (matrix items 1, 5 and 6) made Mark as paid
 * take the OR number and the date paid and record who pressed it
 * (migration 2026_10_21_000100). Every counter payment generated before that
 * has none — 59 of the 59 on a copy of the local data — and OR Records lists
 * only payments carrying an OR (OrRecordController), so it would open on
 * "No OR records yet." in front of the City's BPLO. All owner data on these
 * databases is generated for the defense, and Ken approved giving those
 * payments a believable OR history (3 November 2026).
 *
 * ── Which payments ──────────────────────────────────────────────────────────
 *
 * Completed, paid at the counter, no OR: nothing else. An online or KwikPay
 * payment has no OR by design, and one that already has an OR keeps it, so a
 * second run finds nothing to do. The write itself repeats the `or_number IS
 * NULL` test, so an OR is never overwritten.
 *
 * ── What each one gets ──────────────────────────────────────────────────────
 *
 *   or_date              The Manila date of `paid_at`, the moment the payment
 *                        completed (the generator's clock when BPLO marked it
 *                        paid). `created_at` only if `paid_at` is somehow empty.
 *   recorded_by_user_id  Who actually recorded it, in this order:
 *                        1. the actor of its `payment.completed` audit row —
 *                           Mark as paid wrote one as the officer who pressed it;
 *                        2. else the officer holding the filing's BPLO
 *                           assignment. `officer_user_id` is overwritten in
 *                           place, so this is who holds it now, which is not
 *                           always who pressed the button;
 *                        3. else nobody. OR Records prints a dash.
 *   or_number            Seven digits, like the serial on the City Treasurer's
 *                        AF 51. Ascending with the date paid, with small
 *                        irregular gaps (1–3 between two on the same day, 2–9
 *                        across days) because a booklet is shared with every
 *                        other collection at the window. The range ends below
 *                        Playbook::FIRST_OR_NUMBER and below every seven-digit
 *                        OR already on a payment or a proof, so the receipts
 *                        recorded from here on read as later in the booklet.
 *
 *                        Within one date paid they rise with the payment's
 *                        id, not its time of day: OR Records lists a day's
 *                        receipts by id (OrRecordController), and the
 *                        generator played its owners one after another on a
 *                        moved clock, so time-of-day order made the OR No.
 *                        column read out of order within a day on the
 *                        showcase copy (4199974 under 4199963). On a real
 *                        register the two orders are the same.
 *
 * A number is never one already on a payment, compared the way Mark as paid
 * compares (Payment::withOrNumber: trimmed, any case), nor one an owner has
 * sent as proof. The proofs count because Mark as paid fills its OR box from
 * the owner's proof: a backfilled OR equal to it would make the counter refuse
 * that owner's real payment as a duplicate. A number that is taken is stepped
 * over, so the sequence still rises.
 *
 * The gaps come from a seeded generator, so the same register and the same
 * --seed give the same numbers: a dry run shows exactly what a real run writes.
 *
 * ── No audit rows ───────────────────────────────────────────────────────────
 *
 * Deliberately. Nobody at the counter did this, and the Audit Logs page would
 * show the panel a row of raw keys for it. The existing `payment.completed`
 * row stays the record of who took the money and when.
 *
 * Everything is written in one transaction: all of them or none.
 */
class BackfillCounterOrs extends Command
{
    protected $signature = 'biztrack:backfill-counter-ors
        {--dry-run : Show the ORs it would give; write nothing}
        {--force : Write without asking to confirm}
        {--seed=51 : Seeds the gaps between numbers; the same seed gives the same ORs}';

    protected $description = 'Defense data only: give completed counter payments with no OR an OR number, date paid and who recorded it.';

    /** The lowest number a seven-digit serial can be. */
    private const SEVEN_DIGITS = 1_000_000;

    public function handle(): int
    {
        $seed = (int) $this->option('seed');
        $plan = $this->plan($seed);

        if ($plan === []) {
            $this->info('Every completed counter payment already has an OR. Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $names = User::withTrashed()
                ->whereIn('id', array_filter(array_column($plan, 'recorded_by_user_id')))
                ->pluck('name', 'id');

            $this->table(['Payment', 'Tracking ID', 'Date paid', 'OR No.', 'Recorded by', 'From'], array_map(fn (array $row) => [
                $row['reference'] ?? "#{$row['id']}",
                $row['tracking_id'] ?? '—',
                $row['or_date'],
                $row['or_number'],
                $row['recorded_by_user_id'] ? ($names[$row['recorded_by_user_id']] ?? "user {$row['recorded_by_user_id']}") : '—',
                $row['source'],
            ], $plan));

            $this->info('Dry run: nothing written. Would fill '.$this->summary($plan));

            return self::SUCCESS;
        }

        $this->line(count($plan).' completed counter '.(count($plan) === 1 ? 'payment has' : 'payments have').' no OR.');
        if (! $this->option('force') && ! $this->confirm('Give them an OR number, date paid and who recorded it?', false)) {
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        // Planned again inside the transaction, so what is written is what the register holds now.
        $written = DB::transaction(function () use ($seed) {
            $plan = $this->plan($seed);
            foreach ($plan as $row) {
                Payment::whereKey($row['id'])->whereNull('or_number')->update([
                    'or_number' => $row['or_number'],
                    'or_date' => $row['or_date'],
                    'recorded_by_user_id' => $row['recorded_by_user_id'],
                ]);
            }

            return $plan;
        });

        $this->info($written === [] ? 'Filled nothing: they had their OR by the time it ran.' : 'Filled '.$this->summary($written));

        return self::SUCCESS;
    }

    /**
     * What each payment would get, oldest first.
     *
     * @return list<array{id: int, reference: ?string, tracking_id: ?string, or_date: string, or_number: string, recorded_by_user_id: ?int, source: string}>
     */
    private function plan(int $seed): array
    {
        $payments = Payment::query()
            ->where('method', PaymentMethod::Counter->value)
            ->where('status', PaymentStatus::Completed->value)
            ->whereNull('or_number')
            // A removed filing's payment still gets its receipt, and its BPLO holder.
            ->with(['application' => fn ($q) => $q->withTrashed(), 'application.bploAssignment'])
            ->get();

        if ($payments->isEmpty()) {
            return [];
        }

        // The first actor per payment; a row with no actor (the system) names nobody.
        $actors = AuditLog::query()
            ->where('action', 'payment.completed')
            ->where('auditable_type', Payment::class)
            ->whereIn('auditable_id', $payments->modelKeys())
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get(['auditable_id', 'user_id'])
            ->unique('auditable_id')
            ->pluck('user_id', 'auditable_id');

        $rows = [];
        foreach ($payments as $payment) {
            $actor = $actors[$payment->id] ?? null;
            $holder = $payment->application?->bploAssignment?->officer_user_id;

            $rows[] = [
                'id' => $payment->id,
                'reference' => $payment->reference_number,
                'tracking_id' => $payment->application?->tracking_id,
                'or_date' => ManilaCalendar::dateOf($payment->paid_at ?? $payment->created_at),
                'or_number' => '',
                'recorded_by_user_id' => $actor !== null ? (int) $actor : ($holder !== null ? (int) $holder : null),
                'source' => $actor !== null ? 'audit row' : ($holder !== null ? 'BPLO assignment' : '—'),
            ];
        }

        // By date paid, then id: the order OR Records lists them in, reversed (see the class comment).
        usort($rows, fn (array $a, array $b) => [$a['or_date'], $a['id']] <=> [$b['or_date'], $b['id']]);

        return $this->number($rows, new Randomizer(new Mt19937($seed)));
    }

    /**
     * Seven-digit ORs, rising with the date paid, ending below every one
     * already recorded.
     *
     * @param  list<array<string, mixed>>  $rows  oldest first
     * @return list<array<string, mixed>>
     */
    private function number(array $rows, Randomizer $rng): array
    {
        // How far each OR is past the one before it.
        $steps = [];
        foreach ($rows as $i => $row) {
            $steps[] = match (true) {
                $i === 0 => 0,
                $row['or_date'] === $rows[$i - 1]['or_date'] => $rng->getInt(1, 3),
                default => $rng->getInt(2, 9),
            };
        }
        $span = array_sum($steps);
        $below = $rng->getInt(5, 40);

        $ceiling = min(Playbook::FIRST_OR_NUMBER, $this->lowestSevenDigitOr() ?? Playbook::FIRST_OR_NUMBER);
        $n = $ceiling - $below - $span;

        /*
         * An OR typed far down the range (1000123, say) leaves no room under
         * it for seven digits. Then the run goes under the generators' base
         * instead and steps over whatever it meets there.
         */
        if ($n < self::SEVEN_DIGITS) {
            $n = Playbook::FIRST_OR_NUMBER - $below - $span;
        }

        foreach ($rows as $i => $row) {
            $n += $steps[$i];
            while ($this->taken((string) $n)) {
                $n++;
            }
            $rows[$i]['or_number'] = (string) $n;
        }

        return $rows;
    }

    /** On a payment (the duplicate rule Mark as paid applies), or on an owner's proof. */
    private function taken(string $or): bool
    {
        return Payment::withOrNumber($or)->exists()
            || DB::table('payment_proofs')->whereRaw('LOWER(TRIM(or_number)) = ?', [mb_strtolower(trim($or))])->exists();
    }

    /** The lowest OR on a payment or a proof that reads as a seven-digit serial. */
    private function lowestSevenDigitOr(): ?int
    {
        return Payment::whereNotNull('or_number')->pluck('or_number')
            ->merge(DB::table('payment_proofs')->whereNotNull('or_number')->pluck('or_number'))
            ->map(fn ($or) => trim((string) $or))
            ->filter(fn (string $or) => preg_match('/^\d{7}$/', $or) === 1)
            ->map(fn (string $or) => (int) $or)
            ->min();
    }

    /** @param  list<array<string, mixed>>  $plan */
    private function summary(array $plan): string
    {
        $count = count($plan);
        $nobody = count(array_filter($plan, fn (array $row) => $row['recorded_by_user_id'] === null));

        return sprintf(
            '%d counter %s, OR %s to %s; %d with no recorded_by.',
            $count,
            $count === 1 ? 'payment' : 'payments',
            $plan[0]['or_number'],
            $plan[$count - 1]['or_number'],
            $nobody,
        );
    }
}
