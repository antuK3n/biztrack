<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Support\RenewalOutcomes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The renewal outcome is DERIVED, not recorded, and everything downstream of it
 * — the coefficients, the AUC, the figure an officer reads — is only as sound as
 * the derivation. So this file tests the derivation itself against permit
 * histories built one row at a time, where the right answer is known by
 * construction rather than by inspecting a seeded register.
 *
 * The leakage tests are the ones that matter. A label bug shows up as a number
 * that looks wrong; a leakage bug shows up as a number that looks EXCELLENT, and
 * the only defence against it is a fixture where the future is deliberately made
 * different from the past and the features are checked for having noticed.
 */

/**
 * A business with an address, so the permits below have somewhere to hang.
 */
function outcomeBusiness(string $name): int
{
    return (int) DB::table('businesses')->insertGetId([
        'name' => $name,
        'owner_user_id' => DB::table('users')->value('id'),
        'trade_name' => $name,
        'status' => 'active',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);
}

/**
 * One permit. `issuedAt` defaults to the day cover begins, which is the ordinary
 * case; the tests that care about early issuance pass it explicitly.
 */
function outcomePermit(
    int $businessId,
    string $from,
    string $until,
    ?string $issuedAt = null,
    string $status = PermitStatus::Expired->value,
    ?int $typeId = null,
): int {
    static $n = 0;
    $n++;

    return (int) DB::table('permits')->insertGetId([
        'permit_number' => 'OUT-'.$businessId.'-'.$n,
        'application_id' => DB::table('applications')->value('id') ?? 1,
        'business_id' => $businessId,
        'permit_type_id' => $typeId ?? DB::table('permit_types')->value('id'),
        'status' => $status,
        'valid_from' => $from,
        'valid_until' => $until,
        'issued_at' => $issuedAt ?? $from.' 00:00:00',
        'created_at' => $issuedAt ?? $from.' 00:00:00',
        'updated_at' => $issuedAt ?? $from.' 00:00:00',
    ]);
}

/** The cycle whose prior permit is `$permitId`, or null if it was not labelled. */
function outcomeCycleFor(array $cycles, int $permitId): ?array
{
    foreach ($cycles as $cycle) {
        if ($cycle['permit_id'] === $permitId) {
            return $cycle;
        }
    }

    return null;
}

beforeEach(function () {
    // These tests reason about "how long ago did this lapse", so the clock has to
    // stand still or the settle window moves under them.
    CarbonImmutable::setTestNow('2026-08-07 09:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('calls a renewal late only when the new permit begins after the grace day', function () {
    $clean = outcomeBusiness('Turn of the year');
    $prior = outcomePermit($clean, '2023-01-01', '2023-12-31');
    outcomePermit($clean, '2024-01-01', '2024-12-31');

    $lapsed = outcomeBusiness('Three weeks uncovered');
    $late = outcomePermit($lapsed, '2023-01-01', '2023-12-31');
    outcomePermit($lapsed, '2024-01-22', '2025-01-21');

    $cycles = RenewalOutcomes::cycles();

    /*
     * The single most consequential line in the whole derivation. A permit
     * running to 31 December succeeded by one from 1 January is the register's
     * normal punctual pattern, and a zero-day grace would label every clean
     * renewal in Malabon as late.
     */
    expect(outcomeCycleFor($cycles, $prior)['late'])->toBe(0);
    expect(outcomeCycleFor($cycles, $late)['late'])->toBe(1);
    expect(outcomeCycleFor($cycles, $late)['gap_days'])->toBe(22);
});

it('never labels a permit that has nothing after it', function () {
    $business = outcomeBusiness('Still in force');
    $only = outcomePermit($business, '2025-01-01', '2025-12-31', status: PermitStatus::Active->value);

    /*
     * Right-censoring. This permit may yet be renewed punctually, renewed late,
     * or never renewed at all, and the register cannot tell which. Scoring it
     * as on-time — the tempting shortcut, because "no gap has happened yet" —
     * would load every recent permit into the negative class and teach the model
     * that recency means safety.
     */
    expect(outcomeCycleFor(RenewalOutcomes::cycles(), $only))->toBeNull();
});

it('leaves revoked and suspended permits out of the chain entirely', function () {
    $business = outcomeBusiness('Enforcement, not renewal');
    $revoked = outcomePermit($business, '2023-01-01', '2023-12-31', status: PermitStatus::Revoked->value);
    $after = outcomePermit($business, '2024-06-01', '2025-05-31');
    outcomePermit($business, '2025-06-01', '2026-05-31');

    $cycles = RenewalOutcomes::cycles();

    // A permit that was taken away did not lapse, and the six-month gap after it
    // is an enforcement history rather than a late renewal. No reminder would
    // have changed it, so it is not an example to learn from.
    expect(outcomeCycleFor($cycles, $revoked))->toBeNull();
    // The chain resumes cleanly on the far side rather than being poisoned.
    expect(outcomeCycleFor($cycles, $after)['late'])->toBe(0);
});

it('keeps each permit type on its own chain', function () {
    $business = outcomeBusiness('Three permits, one expiry');
    $types = DB::table('permit_types')->orderBy('id')->limit(2)->pluck('id')->all();

    if (count($types) < 2) {
        test()->markTestSkipped('The reference data holds fewer than two permit types.');
    }

    $businessPermit = outcomePermit($business, '2023-01-01', '2023-12-31', typeId: $types[0]);
    outcomePermit($business, '2024-01-01', '2024-12-31', typeId: $types[0]);
    $sanitary = outcomePermit($business, '2023-01-01', '2023-12-31', typeId: $types[1]);
    outcomePermit($business, '2024-03-15', '2025-03-14', typeId: $types[1]);

    $cycles = RenewalOutcomes::cycles();

    // Pooled into one chain, these four permits would read as three renewals of
    // one thing and the ordering would interleave two different renewals. Kept
    // apart, one is punctual and the other is 75 days late — which is the truth.
    expect(outcomeCycleFor($cycles, $businessPermit)['late'])->toBe(0);
    expect(outcomeCycleFor($cycles, $sanitary)['late'])->toBe(1);
});

it('holds a cycle back until enough time has passed for a late renewal to show up', function () {
    $recent = outcomeBusiness('Lapsed last month');
    $tooNew = outcomePermit($recent, '2025-07-01', '2026-06-30');
    outcomePermit($recent, '2026-07-01', '2027-06-30');

    $old = outcomeBusiness('Lapsed two years ago');
    $settled = outcomePermit($old, '2023-01-01', '2023-12-31');
    outcomePermit($old, '2024-01-01', '2024-12-31');

    $labelled = RenewalOutcomes::labelled();
    $ids = array_column($labelled['cycles'], 'permit_id');

    /*
     * The administrative close. Both of these renewals were punctual, so it is
     * tempting to take both — and taking the recent one is exactly the bias:
     * a punctual renewal is on file the day it happens, while a late one is
     * invisible until it arrives. Admitting recent cycles therefore admits the
     * punctual half of them and almost none of the late half, and the sample's
     * late rate collapses towards zero as it approaches today.
     */
    expect($ids)->toContain($settled);
    expect($ids)->not->toContain($tooNew);
    expect($labelled['counts']['cycles_unsettled'])->toBeGreaterThan(0);
});

/**
 * A handful of settled cycles across several years, for the tests that need a
 * sample rather than a single case.
 *
 * The demo seeder leaves almost nothing old enough to have settled — correct
 * behaviour, useless as a fixture. Building the history here keeps these tests
 * about the derivation rather than about the seeder.
 */
function outcomeHistory(): void
{
    foreach ([['Alpha Trading', 0], ['Beta Foods', 40], ['Gamma Hardware', 80]] as [$name, $shift]) {
        $business = outcomeBusiness($name);
        $anchor = CarbonImmutable::parse('2021-01-01')->addDays($shift);

        for ($year = 0; $year < 4; $year++) {
            $start = $anchor->addYears($year);
            // Every third cycle lapses for a month before the next one begins,
            // so both outcomes appear on both sides of any sensible cutoff.
            $late = $year % 3 === 2;
            outcomePermit(
                $business,
                $start->addDays($late ? 30 : 0)->toDateString(),
                $start->addYears(1)->subDay()->toDateString(),
            );
        }
    }
}

it('splits by time, never at random, and keeps every row of a cycle on one side', function () {
    outcomeHistory();
    $labelled = RenewalOutcomes::labelled();

    expect($labelled['cutoff'])->not->toBeNull();
    expect($labelled['rows'])->not->toBeEmpty();

    $sides = [];
    foreach ($labelled['rows'] as $row) {
        $sides[$row['cycle_id']][$row['split']] = true;

        // The split is a function of the cycle's expiry date and nothing else.
        // If this ever fails, a row has been assigned by something other than
        // time — which is how the future gets into the training set.
        $expected = strcmp($row['expires_on'], $labelled['cutoff']) < 0 ? 'train' : 'test';
        expect($row['split'])->toBe($expected);
    }

    foreach ($sides as $cycleId => $seen) {
        expect(count($seen))->toBe(1, "Cycle {$cycleId} has rows on both sides of the split.");
    }
});

/*
 * ── LEAKAGE ────────────────────────────────────────────────────────────────
 *
 * Everything below exists to fail loudly if a feature learns something it could
 * not have known. These are the tests that stop a beautiful AUC being a lie.
 */

it('takes no observation once the answer is already on the register', function () {
    $business = outcomeBusiness('Renewed six months early');
    $prior = outcomePermit($business, '2023-01-01', '2023-12-31');
    // Issued in October for cover starting in January: from October onwards,
    // anyone reading the register can see the renewal happened.
    outcomePermit($business, '2024-01-01', '2024-12-31', issuedAt: '2023-10-01 10:00:00');

    $rows = array_values(array_filter(
        RenewalOutcomes::labelled()['rows'],
        static fn (array $row): bool => $row['permit_id'] === $prior,
    ));

    expect($rows)->not->toBeEmpty('The cycle produced no observations at all.');

    foreach ($rows as $row) {
        // Every remaining observation must predate the day the successor became
        // visible. An observation on or after it is not an estimate, it is a
        // lookup — and it would be a lookup that always agrees with the answer.
        expect(strcmp($row['as_at'], '2023-10-01') < 0)->toBeTrue(
            "Observed on {$row['as_at']}, after the successor permit was already on the register.",
        );
    }

    // The 180-day mark falls on 2023-07-04, comfortably before issuance, so it
    // survives — which is what makes the assertion above a real filter rather
    // than a fixture that happened to produce nothing.
    expect(array_column($rows, 'days_to_expiry'))->toContain(180);
});

it('takes no observation once the renewal has been approved', function () {
    $business = outcomeBusiness('Approved in August');
    $prior = outcomePermit($business, '2023-01-01', '2023-12-31');
    outcomePermit($business, '2024-01-01', '2024-12-31', issuedAt: '2024-01-01 00:00:00');

    DB::table('applications')->insert([
        'tracking_id' => 'OUT-APPROVED-1',
        'business_id' => $business,
        'applicant_user_id' => DB::table('users')->value('id'),
        'application_type' => ApplicationType::Renewal->value,
        'status' => ApplicationStatus::Approved->value,
        'prior_permit_id' => $prior,
        'created_at' => '2023-08-01 09:00:00',
        'submitted_at' => '2023-08-02 09:00:00',
        'decided_at' => '2023-08-20 09:00:00',
        'updated_at' => '2023-08-20 09:00:00',
    ]);

    $rows = array_values(array_filter(
        RenewalOutcomes::labelled()['rows'],
        static fn (array $row): bool => $row['permit_id'] === $prior,
    ));

    foreach ($rows as $row) {
        expect(strcmp($row['as_at'], '2023-08-20') < 0)->toBeTrue(
            "Observed on {$row['as_at']}, after the renewal was already granted.",
        );
        // And the consequence that makes the whole feature set honest: `approved`
        // is not a level the model can ever see, so it can never be the reason a
        // figure is low.
        expect($row['renewal_stage'])->not->toBe('approved');
    }
});

it('reads the renewal stage as at the observation, not as it stands today', function () {
    $business = outcomeBusiness('Filed late in the window');
    $prior = outcomePermit($business, '2023-01-01', '2023-12-31');
    outcomePermit($business, '2024-04-01', '2025-03-31');

    DB::table('applications')->insert([
        'tracking_id' => 'OUT-STAGE-1',
        'business_id' => $business,
        'applicant_user_id' => DB::table('users')->value('id'),
        'application_type' => ApplicationType::Renewal->value,
        'status' => ApplicationStatus::UnderReview->value,
        'prior_permit_id' => $prior,
        'created_at' => '2023-12-20 09:00:00',
        'submitted_at' => '2023-12-26 09:00:00',
        'updated_at' => '2023-12-26 09:00:00',
    ]);

    $rows = collect(RenewalOutcomes::labelled()['rows'])
        ->where('permit_id', $prior)
        ->keyBy('days_to_expiry');

    /*
     * The filing exists TODAY, and reading `applications.status` would report it
     * as under review at every observation — including the ones months before
     * anybody started the form. The stage has to be reconstructed from the
     * filing's own dates or every early observation carries information from its
     * own future.
     */
    expect($rows[180]['renewal_stage'])->toBe('none');   // 2023-07-04, no form yet
    expect($rows[15]['renewal_stage'])->toBe('none');    // 2023-12-16, still nothing
    expect($rows[7]['renewal_stage'])->toBe('draft');    // 2023-12-24, started not sent
    expect($rows[1]['renewal_stage'])->toBe('in_progress'); // 2023-12-30, with the LGU
});

it('counts a business\'s earlier renewals only from the day each one was settled', function () {
    $business = outcomeBusiness('Late once, then watched');
    // Cycle one: lapses 2022-12-31, renewed 2023-03-01 — late, and known to be
    // late from 2023-01-02, the first day an on-time start became impossible.
    $first = outcomePermit($business, '2022-01-01', '2022-12-31');
    // Cycle two: the one being observed. Its own successor begins the day after
    // it lapses, so it is punctual — irrelevant to what the feature must say.
    $second = outcomePermit($business, '2023-03-01', '2024-02-29');
    outcomePermit($business, '2024-03-01', '2025-02-28');

    $rows = collect(RenewalOutcomes::labelled()['rows'])->where('permit_id', $second);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        // Cycle one settled long before any observation of cycle two, so it
        // counts — but the cycle being labelled never counts towards its own
        // history, which would be feeding the answer in as a feature.
        expect($row['prior_cycles'])->toBe(1);
        expect($row['prior_late'])->toBe(1);
        expect($row['punctuality_known'])->toBe(1);
    }

    // And the first cycle itself has no record to draw on: nothing preceded it.
    foreach (collect(RenewalOutcomes::labelled()['rows'])->where('permit_id', $first) as $row) {
        expect($row['prior_cycles'])->toBe(0);
        expect($row['punctuality_known'])->toBe(0);
    }
});

it('never counts an earlier cycle whose own outcome had not settled yet', function () {
    $business = outcomeBusiness('History that had not happened');
    // Cycle A lapses 2024-12-31 and is renewed 300 days late, on 2025-10-27.
    // Lateness is certain from 2025-01-02 — not from 2025-10-27.
    $a = outcomePermit($business, '2024-01-01', '2024-12-31');
    outcomePermit($business, '2025-10-27', '2026-10-26');

    $rows = collect(RenewalOutcomes::labelled()['rows'])->where('permit_id', $a);

    foreach ($rows as $row) {
        /*
         * Every observation of cycle A happens during 2024, before ANY cycle of
         * this business had settled. A punctuality figure here would be built
         * from A's own future.
         */
        expect($row['prior_cycles'])->toBe(0);
        expect($row['prior_late'])->toBe(0);
    }
});

it('produces only the stages and fee states the model declares', function () {
    outcomeHistory();
    $rows = RenewalOutcomes::labelled()['rows'];

    expect($rows)->not->toBeEmpty('The register produced no labelled observations.');

    foreach ($rows as $row) {
        expect($row['renewal_stage'])->toBeIn(RenewalOutcomes::STAGES);
        expect($row['fee_state'])->toBeIn(RenewalOutcomes::FEE_STATES);
        // Never past expiry: the day after the grace period closes, lateness is a
        // fact and there is nothing left to estimate.
        expect($row['days_to_expiry'])->toBeGreaterThan(0);
        expect($row['late'])->toBeIn([0, 1]);
        expect($row['prior_late'])->toBeLessThanOrEqual($row['prior_cycles']);
    }
});
