<?php

use App\Models\Payment;
use App\Models\User;
use App\Support\DefenseAccounts\Playbook;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * biztrack:backfill-counter-ors gives the counter payments made before the OR
 * was asked for an OR number, the date paid and who recorded it, so OR Records
 * is not empty at the defense. Defense data only (the command's docblock).
 *
 * Rows are written straight to the tables, as the analytics fixtures do: what
 * is under test is which payment, when it was paid, and what its audit row and
 * its filing's BPLO assignment say — not the flow that made it.
 */

const BACKFILL_CONFIRM = 'Give them an OR number, date paid and who recorded it?';

/**
 * A payment on a filing of its own: a completed counter payment with no OR
 * unless the attributes say otherwise. Answers its id.
 *
 * @param  array<string, mixed>  $attrs
 */
function backfillPayment(array $attrs = []): int
{
    $applicationId = anaFiling(anaBusiness());
    $feeId = DB::table('fee_assessments')->insertGetId([
        'application_id' => $applicationId,
        'line_items' => json_encode([['label' => 'Business tax', 'amount' => 1500]]),
        'total_amount' => 1500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $paidAt = $attrs['paid_at'] ?? now()->subDays(10)->toDateTimeString();

    return DB::table('payments')->insertGetId($attrs + [
        'application_id' => $applicationId,
        'fee_assessment_id' => $feeId,
        'reference_number' => 'PAY-TEST-'.Str::upper(Str::random(10)),
        'amount' => 1500,
        'method' => 'counter',
        'status' => 'completed',
        'gateway' => 'simulated',
        'paid_at' => $paidAt,
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);
}

/** An audit row about the payment, as Audit::log writes one: actor in user_id, subject in auditable_*. */
function backfillAudit(int $paymentId, ?int $actorId, string $action = 'payment.completed'): void
{
    DB::table('audit_logs')->insert([
        'user_id' => $actorId,
        'action' => $action,
        'auditable_type' => Payment::class,
        'auditable_id' => $paymentId,
        'changes' => json_encode(['amount' => '1500.00', 'via' => 'counter']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The payment's filing is held at this office by this officer. */
function backfillHolder(int $paymentId, string $departmentCode, ?int $officerId): void
{
    anaAssignment((int) DB::table('payments')->where('id', $paymentId)->value('application_id'), $departmentCode, [
        'officer_user_id' => $officerId,
    ]);
}

function backfillUserId(string $email): int
{
    return (int) User::where('email', $email)->value('id');
}

/** @return array<int, array<string, mixed>> every payment row, by id */
function backfillPayments(): array
{
    return DB::table('payments')->orderBy('id')->get()->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
}

/** @param  list<int>  $ids  @return list<string> their ORs, by date paid and then id */
function backfillOrsByDate(array $ids): array
{
    return DB::table('payments')->whereIn('id', $ids)->orderBy('or_date')->orderBy('id')->pluck('or_number')->all();
}

it('fills completed counter payments that have no OR, and leaves online, KwikPay, unfinished and already-receipted ones exactly as they were', function () {
    $target = backfillPayment();
    backfillPayment(['status' => 'pending']);
    backfillPayment(['status' => 'refunded']);
    backfillPayment(['method' => 'gcash']);
    backfillPayment(['method' => 'maya', 'gateway' => 'kwikpay']);
    backfillPayment(['or_number' => '4300001', 'or_date' => '2026-01-05', 'recorded_by_user_id' => backfillUserId('bplo@biztrack.local')]);

    $before = backfillPayments();
    unset($before[$target]);

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])->assertSuccessful();

    $after = backfillPayments();
    expect($after[$target]['or_number'])->toMatch('/^\d{7}$/')
        ->and($after[$target]['or_date'])->not->toBeNull();

    unset($after[$target]);
    expect($after)->toBe($before);
});

it('gives each a unique seven-digit OR that rises with the date paid, in small irregular steps', function () {
    // Written out of date order, so the ids do not already sort by date.
    $ids = [
        backfillPayment(['paid_at' => '2026-02-10 09:15:00']),
        backfillPayment(['paid_at' => '2026-01-20 14:00:00']),
        backfillPayment(['paid_at' => '2026-02-10 10:40:00']),
        backfillPayment(['paid_at' => '2026-03-03 23:30:00']),
        backfillPayment(['paid_at' => '2026-03-04 07:30:00']),
        backfillPayment(['paid_at' => '2026-01-05 08:05:00']),
        backfillPayment(['paid_at' => '2026-02-10 15:55:00']),
    ];

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])->assertSuccessful();

    // The Manila date of the moment it was paid, late evening and early morning alike.
    expect(DB::table('payments')->whereIn('id', $ids)->orderBy('paid_at')->pluck('or_date')->map(fn ($d) => substr((string) $d, 0, 10))->all())
        ->toBe(['2026-01-05', '2026-01-20', '2026-02-10', '2026-02-10', '2026-02-10', '2026-03-03', '2026-03-04']);

    $ors = backfillOrsByDate($ids);
    $numbers = array_map('intval', $ors);
    $steps = array_map(fn ($a, $b) => $b - $a, array_slice($numbers, 0, -1), array_slice($numbers, 1));

    expect($ors)->each->toMatch('/^\d{7}$/')
        ->and(array_unique($ors))->toHaveCount(count($ids))
        ->and(max($numbers))->toBeLessThan(Playbook::FIRST_OR_NUMBER)
        ->and(min($steps))->toBeGreaterThanOrEqual(1)
        ->and(max($steps))->toBeLessThanOrEqual(9)
        ->and(array_unique($steps))->not->toHaveCount(1);
});

it('rises within one date paid in the order OR Records lists them, not by time of day', function () {
    // The generator's clock: the filing made first was paid later in the day.
    $first = backfillPayment(['paid_at' => '2026-02-10 15:30:00']);
    $second = backfillPayment(['paid_at' => '2026-02-10 09:10:00']);

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])->assertSuccessful();

    $or = fn (int $id) => (int) DB::table('payments')->where('id', $id)->value('or_number');
    expect($or($first))->toBeLessThan($or($second));

    authAs('bplo@biztrack.local');
    $column = array_map('intval', array_column($this->getJson('/api/v1/or-records')->assertOk()->json('data'), 'or_number'));
    expect($column)->toBe(collect($column)->sortDesc()->values()->all());
});

it('never gives an OR already on a payment or an owner\'s proof, and ends below the lowest one recorded', function () {
    // As typed at the counter: spaces around it are still the same OR.
    backfillPayment(['method' => 'counter', 'or_number' => ' 4150000 ', 'or_date' => '2026-03-01']);
    $proofFiling = anaFiling(anaBusiness());
    DB::table('payment_proofs')->insert([
        'application_id' => $proofFiling,
        'or_number' => '4140000',
        'or_date' => '2026-03-02',
        'submitted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $ids = [backfillPayment(['paid_at' => '2026-01-10 09:00:00']), backfillPayment(['paid_at' => '2026-01-12 09:00:00']), backfillPayment(['paid_at' => '2026-01-15 09:00:00'])];

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])->assertSuccessful();

    $numbers = array_map('intval', backfillOrsByDate($ids));
    expect(max($numbers))->toBeLessThan(4_140_000);
});

it('steps over a taken number, compared trimmed, when an OR typed low in the range leaves no room under it', function () {
    // 1000005 leaves no seven-digit room below it, so the run goes under the generators' base.
    backfillPayment(['method' => 'gcash', 'or_number' => '1000005']);
    $ids = [backfillPayment(['paid_at' => '2026-01-10 09:00:00']), backfillPayment(['paid_at' => '2026-01-12 09:00:00']), backfillPayment(['paid_at' => '2026-01-15 09:00:00']), backfillPayment(['paid_at' => '2026-01-15 11:00:00'])];

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true, '--seed' => 7])->assertSuccessful();
    $first = backfillOrsByDate($ids);

    // The same seed on the same payments picks the same numbers — unless they are taken.
    DB::table('payments')->whereIn('id', $ids)->update(['or_number' => null, 'or_date' => null, 'recorded_by_user_id' => null]);
    backfillPayment(['method' => 'gcash', 'or_number' => " {$first[0]} "]);
    backfillPayment(['method' => 'counter', 'or_number' => $first[2], 'or_date' => '2026-03-01']);

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true, '--seed' => 7])->assertSuccessful();
    $second = backfillOrsByDate($ids);
    $numbers = array_map('intval', $second);

    expect((int) $first[0])->toBeLessThan(Playbook::FIRST_OR_NUMBER)
        ->and((int) $second[0])->toBe((int) $first[0] + 1)
        ->and($second)->not->toContain($first[0])
        ->and($second)->not->toContain($first[2])
        ->and(array_unique($second))->toHaveCount(4)
        ->and($numbers)->toBe(collect($numbers)->sort()->values()->all());
});

it('names who recorded it: the actor of its payment.completed row, else the officer holding BPLO\'s assignment, else nobody', function () {
    $bplo = backfillUserId('bplo@biztrack.local');
    $admin = backfillUserId('admin@biztrack.local');
    $sanitary = backfillUserId('sanitary@biztrack.local');

    $audited = backfillPayment(['paid_at' => '2026-01-10 09:00:00']);
    backfillAudit($audited, $bplo);
    backfillHolder($audited, 'BPLO', $admin);

    // A system row names nobody, and another action's actor is not the recorder.
    $held = backfillPayment(['paid_at' => '2026-01-11 09:00:00']);
    backfillAudit($held, null);
    backfillAudit($held, $sanitary, 'payment.abandoned');
    backfillHolder($held, 'BPLO', $admin);

    // Another office's officer did not take the money; an unheld BPLO assignment names nobody.
    $unknown = backfillPayment(['paid_at' => '2026-01-12 09:00:00']);
    backfillHolder($unknown, 'CHO', $sanitary);
    backfillHolder($unknown, 'BPLO', null);

    $bare = backfillPayment(['paid_at' => '2026-01-13 09:00:00']);

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])
        ->expectsOutputToContain('2 with no recorded_by.')
        ->assertSuccessful();

    expect(DB::table('payments')->whereIn('id', [$audited, $held, $unknown, $bare])->orderBy('id')->pluck('recorded_by_user_id')->all())
        ->toBe([$bplo, $admin, null, null]);
});

it('writes nothing on a dry run, and shows the ORs a real run then writes', function () {
    $ids = [backfillPayment(['paid_at' => '2026-01-10 09:00:00']), backfillPayment(['paid_at' => '2026-01-20 09:00:00'])];
    backfillAudit($ids[0], backfillUserId('bplo@biztrack.local'));
    $before = backfillPayments();
    $audits = DB::table('audit_logs')->count();

    Artisan::call('biztrack:backfill-counter-ors', ['--dry-run' => true]);
    $dry = Artisan::output();

    expect($dry)->toContain('Dry run: nothing written. Would fill 2 counter payments')
        ->and(backfillPayments())->toBe($before)
        ->and(DB::table('audit_logs')->count())->toBe($audits);

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])->assertSuccessful();
    foreach (backfillOrsByDate($ids) as $or) {
        expect($dry)->toContain($or);
    }
});

it('asks before writing, writes nothing on a no, and adds no audit rows on a yes', function () {
    $id = backfillPayment();
    $before = backfillPayments();
    $audits = DB::table('audit_logs')->count();

    $this->artisan('biztrack:backfill-counter-ors')
        ->expectsOutputToContain('1 completed counter payment has no OR.')
        ->expectsConfirmation(BACKFILL_CONFIRM, 'no')
        ->expectsOutputToContain('Nothing written.')
        ->assertSuccessful();
    expect(backfillPayments())->toBe($before);

    $this->artisan('biztrack:backfill-counter-ors')
        ->expectsConfirmation(BACKFILL_CONFIRM, 'yes')
        ->expectsOutputToContain('Filled 1 counter payment, OR ')
        ->assertSuccessful();
    expect(DB::table('payments')->where('id', $id)->value('or_number'))->not->toBeNull()
        ->and(DB::table('audit_logs')->count())->toBe($audits);
});

it('changes nothing the second time it runs', function () {
    backfillPayment(['paid_at' => '2026-01-10 09:00:00']);
    backfillPayment(['paid_at' => '2026-01-20 09:00:00']);

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])->assertSuccessful();
    $once = backfillPayments();

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])
        ->expectsOutputToContain('Nothing to do.')
        ->assertSuccessful();

    expect(backfillPayments())->toBe($once);
});

it('puts the filled payments on OR Records, a nameless one with no recorded_by', function () {
    $named = backfillPayment(['paid_at' => '2026-01-10 09:00:00']);
    backfillAudit($named, backfillUserId('bplo@biztrack.local'));
    backfillPayment(['paid_at' => '2026-01-20 09:00:00']);

    $this->artisan('biztrack:backfill-counter-ors', ['--force' => true])->assertSuccessful();

    authAs('bplo@biztrack.local');
    $rows = $this->getJson('/api/v1/or-records')->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'or_date'))->toBe(['2026-01-20', '2026-01-10'])
        ->and($rows[0]['recorded_by'])->toBeNull()
        ->and($rows[1]['recorded_by']['name'])->toBe(User::where('email', 'bplo@biztrack.local')->value('name'));
});
