<?php

use App\Support\RenewalRiskScoring;

/*
 * The renewal risk rule set (App\Support\RenewalRiskScoring).
 *
 * Unlike Spc and Des there is no reference implementation to pin against — the R
 * project never modelled renewal risk, so the weights are a stated design
 * decision rather than a port. What these tests defend is that the score stays
 * an honest, additive, bounded rule set: the weights sum to 100, each rule can
 * only ever contribute its own maximum, the bands are reachable, and every point
 * awarded comes with a driver an officer can read and argue with.
 *
 * If a future change makes the score non-additive or unbounded, these fail — and
 * they should, because the screen's copy promises a weighted rule score.
 */

/**
 * The lowest-risk permit the rules can describe.
 *
 * @return array<string, mixed>
 */
function cleanFacts(array $overrides = []): array
{
    return [
        'days_to_expiry' => 200,
        'renewal_stage' => 'approved',
        'prior_renewals' => 4,
        'late_renewals' => 0,
        'open_findings' => 0,
        'fee_state' => 'settled',
        ...$overrides,
    ];
}

/* ── the shape of the rule set ────────────────────────────────────────── */

it('has weights that sum to one hundred', function () {
    // The score is presented as "n / 100". That has to be literally true.
    expect(array_sum(RenewalRiskScoring::WEIGHTS))->toBe(100);
});

it('publishes a rulebook that matches the weights exactly', function () {
    $rulebook = RenewalRiskScoring::rulebook();

    expect($rulebook)->toHaveCount(count(RenewalRiskScoring::WEIGHTS));
    foreach ($rulebook as $rule) {
        expect(RenewalRiskScoring::WEIGHTS)->toHaveKey($rule['rule']);
        // The screen renders this rather than restating the weights in prose,
        // so a drift between the two would be a lie on the page.
        expect($rule['max'])->toBe(RenewalRiskScoring::WEIGHTS[$rule['rule']]);
        expect($rule['description'])->not->toBeEmpty();
    }
});

it('scores the cleanest possible permit at zero and the worst at one hundred', function () {
    expect(RenewalRiskScoring::score(cleanFacts())['score'])->toBe(0);

    $worst = RenewalRiskScoring::score([
        'days_to_expiry' => -30,
        'renewal_stage' => 'none',
        'prior_renewals' => 3,
        'late_renewals' => 3,
        'open_findings' => 9,
        'fee_state' => 'unpaid',
    ]);

    expect($worst['score'])->toBe(100);
    expect($worst['band'])->toBe('high');
});

it('never exceeds a rule\'s own maximum, whatever the input', function () {
    // Adversarial inputs: more late renewals than renewals, absurd counts.
    $result = RenewalRiskScoring::score([
        'days_to_expiry' => -100000,
        'renewal_stage' => 'not_a_real_stage',
        'prior_renewals' => 2,
        'late_renewals' => 500,
        'open_findings' => 100000,
        'fee_state' => 'not_a_real_state',
    ]);

    expect($result['score'])->toBeLessThanOrEqual(100);
    foreach ($result['drivers'] as $driver) {
        expect($driver['points'])->toBeLessThanOrEqual($driver['max']);
        expect($driver['points'])->toBeGreaterThanOrEqual(0);
    }
});

it('is additive: the score is the sum of its drivers', function () {
    $facts = [
        'days_to_expiry' => 20,
        'renewal_stage' => 'draft',
        'prior_renewals' => 4,
        'late_renewals' => 1,
        'open_findings' => 2,
        'fee_state' => 'pending',
    ];

    $result = RenewalRiskScoring::score($facts);

    expect(array_sum(array_column($result['drivers'], 'points')))->toBe($result['score']);
    expect($result['drivers'])->toHaveCount(count(RenewalRiskScoring::WEIGHTS));
});

it('explains every rule it applied, in descending order of impact', function () {
    $result = RenewalRiskScoring::score([
        'days_to_expiry' => 5,
        'renewal_stage' => 'none',
        'prior_renewals' => 0,
        'late_renewals' => 0,
        'open_findings' => 1,
        'fee_state' => 'settled',
    ]);

    $points = array_column($result['drivers'], 'points');
    expect($points)->toBe(collect($points)->sortDesc()->values()->all());

    foreach ($result['drivers'] as $driver) {
        // A number with no stated reason is the thing this screen must not ship.
        expect($driver['detail'])->not->toBeEmpty();
        expect($driver['label'])->not->toBeEmpty();
    }
});

/* ── individual rules ─────────────────────────────────────────────────── */

it('steps time to expiry on the paper\'s monitoring marks', function () {
    $points = [];
    foreach ([-1, 0, 1, 7, 15, 30, 60, 90, 120] as $days) {
        $result = RenewalRiskScoring::score(cleanFacts(['days_to_expiry' => $days]));
        $points[$days] = collect($result['drivers'])->firstWhere('rule', 'expiry')['points'];
    }

    // A lapsed permit, one expiring today, and one expiring tomorrow all take
    // full weight.
    expect($points[-1])->toBe(RenewalRiskScoring::WEIGHTS['expiry']);
    expect($points[0])->toBe(RenewalRiskScoring::WEIGHTS['expiry']);
    expect($points[1])->toBe(RenewalRiskScoring::WEIGHTS['expiry']);

    // Then it steps down strictly at 7, 15 and 30 days — the marks the paper
    // says expiration monitoring runs at — and dies past 90.
    expect($points[7])->toBeLessThan($points[1]);
    expect($points[15])->toBeLessThan($points[7]);
    expect($points[30])->toBeLessThan($points[15]);
    expect($points[60])->toBeLessThan($points[30]);
    expect($points[90])->toBeLessThan($points[60]);
    expect($points[120])->toBe(0);
});

it('reaches High for a permit a week out with nothing filed', function () {
    // The 7-day mark with no renewal on file is the case a reminder cadence is
    // supposed to catch; it must not sit in Moderate.
    $result = RenewalRiskScoring::score(cleanFacts([
        'days_to_expiry' => 7,
        'renewal_stage' => 'none',
    ]));

    expect($result['band'])->toBe('high');
    expect($result['action'])->toBe('immediate_follow_up');
});

it('says how long ago a permit lapsed rather than showing a negative number', function () {
    $driver = collect(RenewalRiskScoring::score(cleanFacts(['days_to_expiry' => -12]))['drivers'])
        ->firstWhere('rule', 'expiry');

    expect($driver['detail'])->toBe('Lapsed 12 days ago');
});

it('does not penalise a permit that is not yet due for renewal', function () {
    // The bug this guards: charging the full "nothing filed" weight to a permit
    // with ten months left made every permit in the register at least Moderate
    // and reported Low Risk: 0 on screen.
    $notDue = RenewalRiskScoring::score(cleanFacts([
        'days_to_expiry' => 300,
        'renewal_stage' => 'none',
    ]));

    $progress = collect($notDue['drivers'])->firstWhere('rule', 'progress');
    expect($progress['points'])->toBe(0);
    expect($progress['detail'])->toBe('Not yet due for renewal');
    expect($notDue['band'])->toBe('low');

    // The same permit inside the renewal window is a different matter.
    $due = RenewalRiskScoring::score(cleanFacts([
        'days_to_expiry' => RenewalRiskScoring::RENEWAL_DUE_WITHIN_DAYS,
        'renewal_stage' => 'none',
    ]));
    expect(collect($due['drivers'])->firstWhere('rule', 'progress')['points'])
        ->toBe(RenewalRiskScoring::WEIGHTS['progress']);
});

it('still penalises an abandoned or rejected filing however far off expiry is', function () {
    // "Nothing filed yet" is excusable early; "started it and walked away" is not.
    foreach (['draft', 'returned', 'rejected'] as $stage) {
        $points = collect(RenewalRiskScoring::score(cleanFacts([
            'days_to_expiry' => 300,
            'renewal_stage' => $stage,
        ]))['drivers'])->firstWhere('rule', 'progress')['points'];

        expect($points)->toBeGreaterThan(0);
    }
});

it('publishes every number the score depends on for a cross-engine agreement test', function () {
    // The architecture makes R the primary engine and this class the fallback, so
    // both have to be reproducible from one parameter table rather than from
    // literals copied between languages.
    $parameters = RenewalRiskScoring::parameters();

    expect($parameters)->toHaveKeys([
        'weights',
        'thresholds',
        'expiry_bands',
        'progress_points',
        'renewal_due_within_days',
        'punctuality_unknown_points',
        'findings_bands',
        'fee_points',
    ]);

    expect($parameters['weights'])->toBe(RenewalRiskScoring::WEIGHTS);
    expect($parameters['thresholds']['high'])->toBe(RenewalRiskScoring::HIGH_THRESHOLD);
    expect($parameters['renewal_due_within_days'])->toBe(RenewalRiskScoring::RENEWAL_DUE_WITHIN_DAYS);

    // The table has to survive a round trip through JSON, because that is how it
    // will reach any other implementation.
    expect(json_decode(json_encode($parameters), true))->toBe($parameters);

    // Every band's points stay inside the rule's own weight.
    foreach ($parameters['expiry_bands'] as [$days, $points]) {
        expect($points)->toBeLessThanOrEqual($parameters['weights']['expiry']);
        expect($days)->toBeGreaterThan(0);
    }
    foreach ($parameters['progress_points'] as $points) {
        expect($points)->toBeLessThanOrEqual($parameters['weights']['progress']);
    }
    foreach ($parameters['fee_points'] as $points) {
        expect($points)->toBeLessThanOrEqual($parameters['weights']['fees']);
    }
});

it('zeroes the progress rule once a renewal is approved and maxes it when nothing is filed', function () {
    // Held inside the renewal window so the "not yet due" gate is not what is
    // being measured here.
    $progress = function (string $stage): int {
        return collect(RenewalRiskScoring::score(cleanFacts([
            'renewal_stage' => $stage,
            'days_to_expiry' => 10,
        ]))['drivers'])->firstWhere('rule', 'progress')['points'];
    };

    expect($progress('approved'))->toBe(0);
    expect($progress('in_progress'))->toBeGreaterThan(0);
    expect($progress('returned'))->toBeGreaterThan($progress('in_progress'));
    expect($progress('draft'))->toBeGreaterThan($progress('in_progress'));
    // A rejected renewal is back to square one, so it scores like none at all.
    expect($progress('none'))->toBe(RenewalRiskScoring::WEIGHTS['progress']);
    expect($progress('rejected'))->toBe(RenewalRiskScoring::WEIGHTS['progress']);
});

it('scores punctuality on the share of earlier renewals filed late', function () {
    $punctuality = function (int $prior, int $late): int {
        return collect(RenewalRiskScoring::score(cleanFacts([
            'prior_renewals' => $prior,
            'late_renewals' => $late,
        ]))['drivers'])->firstWhere('rule', 'punctuality')['points'];
    };

    expect($punctuality(4, 0))->toBe(0);
    expect($punctuality(4, 2))->toBe(10);
    expect($punctuality(4, 4))->toBe(RenewalRiskScoring::WEIGHTS['punctuality']);
    expect($punctuality(3, 1))->toBe(7);
});

it('treats a first renewal cycle as unknown, not clean', function () {
    $driver = collect(RenewalRiskScoring::score(cleanFacts(['prior_renewals' => 0]))['drivers'])
        ->firstWhere('rule', 'punctuality');

    // Half weight, and it says why — a first-timer with no record should not be
    // buried below businesses with a proven good one.
    expect($driver['points'])->toBe((int) (RenewalRiskScoring::WEIGHTS['punctuality'] / 2));
    expect($driver['detail'])->toContain('First renewal cycle');

    $proven = collect(RenewalRiskScoring::score(cleanFacts(['prior_renewals' => 3, 'late_renewals' => 0]))['drivers'])
        ->firstWhere('rule', 'punctuality');
    expect($proven['points'])->toBeLessThan($driver['points']);
});

it('bands open findings and unsettled fees', function () {
    $findings = fn (int $n): int => collect(RenewalRiskScoring::score(cleanFacts(['open_findings' => $n]))['drivers'])
        ->firstWhere('rule', 'findings')['points'];

    expect($findings(0))->toBe(0);
    expect($findings(1))->toBeGreaterThan(0);
    expect($findings(2))->toBe($findings(1));
    expect($findings(3))->toBe(RenewalRiskScoring::WEIGHTS['findings']);
    expect($findings(50))->toBe(RenewalRiskScoring::WEIGHTS['findings']);

    $fees = fn (string $state): int => collect(RenewalRiskScoring::score(cleanFacts(['fee_state' => $state]))['drivers'])
        ->firstWhere('rule', 'fees')['points'];

    expect($fees('settled'))->toBe(0);
    expect($fees('pending'))->toBeGreaterThan(0);
    expect($fees('unpaid'))->toBe(RenewalRiskScoring::WEIGHTS['fees']);
    expect($fees('unpaid'))->toBeGreaterThan($fees('pending'));
});

/* ── bands and actions ────────────────────────────────────────────────── */

it('reaches High on the two factual signals alone', function () {
    // A lapsed permit with nothing filed is the case the screen exists for. It
    // must be High without needing any behavioural evidence, which is why the
    // threshold sits below expiry + progress.
    $result = RenewalRiskScoring::score([
        'days_to_expiry' => -5,
        'renewal_stage' => 'none',
        'prior_renewals' => 5,
        'late_renewals' => 0,
        'open_findings' => 0,
        'fee_state' => 'settled',
    ]);

    expect($result['score'])->toBe(
        RenewalRiskScoring::WEIGHTS['expiry'] + RenewalRiskScoring::WEIGHTS['progress'],
    );
    expect($result['band'])->toBe('high');
});

it('bands monotonically: more risk signals never lower the band', function () {
    expect(RenewalRiskScoring::HIGH_THRESHOLD)->toBeGreaterThan(RenewalRiskScoring::MODERATE_THRESHOLD);

    // A ladder of strictly worsening fact sets. Scores must rise and bands must
    // never step backwards.
    $ladder = [
        cleanFacts(),
        cleanFacts(['days_to_expiry' => 60]),
        cleanFacts(['days_to_expiry' => 30, 'renewal_stage' => 'in_progress']),
        cleanFacts(['days_to_expiry' => 15, 'renewal_stage' => 'none']),
        cleanFacts(['days_to_expiry' => 7, 'renewal_stage' => 'none', 'open_findings' => 2]),
        cleanFacts([
            'days_to_expiry' => -3,
            'renewal_stage' => 'none',
            'late_renewals' => 4,
            'open_findings' => 5,
            'fee_state' => 'unpaid',
        ]),
    ];

    $rank = ['low' => 0, 'moderate' => 1, 'high' => 2];
    $previousScore = -1;
    $previousRank = -1;

    foreach ($ladder as $facts) {
        $result = RenewalRiskScoring::score($facts);

        expect($result['score'])->toBeGreaterThan($previousScore);
        expect($rank[$result['band']])->toBeGreaterThanOrEqual($previousRank);
        // The band always agrees with the thresholds it was derived from.
        expect($result['band'])->toBe(match (true) {
            $result['score'] >= RenewalRiskScoring::HIGH_THRESHOLD => 'high',
            $result['score'] >= RenewalRiskScoring::MODERATE_THRESHOLD => 'moderate',
            default => 'low',
        });

        $previousScore = $result['score'];
        $previousRank = $rank[$result['band']];
    }

    // The ends of the ladder are the bands the screen exists to separate.
    expect(RenewalRiskScoring::score($ladder[0])['band'])->toBe('low');
    expect(RenewalRiskScoring::score($ladder[count($ladder) - 1])['band'])->toBe('high');
});

it('maps each band onto exactly one recommended action', function () {
    $cases = [
        ['high', 'immediate_follow_up', 'Immediate follow-up'],
        ['moderate', 'send_reminder', 'Send reminder'],
        ['low', 'monitor', 'Monitor'],
    ];

    foreach ($cases as [$band, $action, $label]) {
        // Build a fact set that lands in the wanted band.
        $facts = match ($band) {
            'high' => ['days_to_expiry' => -1, 'renewal_stage' => 'none'],
            'moderate' => ['days_to_expiry' => 15, 'renewal_stage' => 'none'],
            default => ['days_to_expiry' => 200, 'renewal_stage' => 'approved'],
        };

        $result = RenewalRiskScoring::score(cleanFacts([...$facts, 'prior_renewals' => 2, 'late_renewals' => 0]));

        expect($result['band'])->toBe($band);
        expect($result['action'])->toBe($action);
        expect($result['action_label'])->toBe($label);
    }
});

it('never labels the score as a probability or a prediction', function () {
    // A guard on the vocabulary, not the maths. The mockup called this column
    // "PROB. DELAY RISK" and printed percentages; nothing this class emits may
    // reintroduce that claim, in a driver detail or a rulebook description.
    $result = RenewalRiskScoring::score([
        'days_to_expiry' => 10,
        'renewal_stage' => 'none',
        'prior_renewals' => 2,
        'late_renewals' => 1,
        'open_findings' => 3,
        'fee_state' => 'unpaid',
    ]);

    $prose = strtolower(json_encode([$result, RenewalRiskScoring::rulebook()]));

    foreach (['probability', 'probable', 'prob.', 'predict', 'likelihood', 'confidence', '%'] as $forbidden) {
        expect($prose)->not->toContain($forbidden);
    }
});
