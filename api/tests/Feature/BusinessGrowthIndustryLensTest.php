<?php

use App\Support\BusinessGrowthAnalytics;

/*
 * The Business Industry Growth Trend lens toggle.
 *
 * A panelist asked whether there is any criterion for which line of business
 * appears on this chart, and what would happen if all of them did. The register
 * holds 135 PSIC codes; the palette keeps six series apart without colour and
 * no more. So the criterion is now the reader's to choose — Largest, Fastest
 * growing, Fastest declining — over the same six slots.
 *
 * What these tests hold is not the arithmetic (a delta is a subtraction) but the
 * three editorial rules that make the toggle honest, each of which is a decision
 * somebody could quietly undo:
 *
 *  - the minimum business count on the two change lenses, and the fact that it
 *    does NOT touch Largest;
 *  - that a lens with fewer than six qualifying lines draws fewer rather than
 *    padding with lines that did not do the thing the lens names;
 *  - that `industry_growth` — R's key, read by the PDF and by the parity
 *    fixture — is left exactly as the engine produced it.
 *
 * The ranking rules are exercised through computeIndustryLenses() against a
 * register written out by hand, because the interesting cases (a one-business
 * trade that trebled, a lens with nothing in it) are ones the demo seed does not
 * happen to contain and should not be edited to contain.
 */

/**
 * One line of business, as industryCounts() hands it over.
 *
 * @return array{industry: string, psic_code: string, count: int, registrations: int, prior: int}
 */
function industryFact(string $code, int $count, int $registrations, int $prior): array
{
    return [
        'industry' => "Line {$code}",
        'psic_code' => $code,
        'count' => $count,
        'registrations' => $registrations,
        'prior' => $prior,
    ];
}

/** @param array<string, mixed> $lenses */
function lensNamed(array $lenses, string $key): array
{
    return collect($lenses['lenses'])->firstWhere('key', $key);
}

it('keeps a tiny line of business off the change lenses without hiding it from Largest', function () {
    /*
     * The failure this is the guard on, in the shape it actually takes on the
     * register: "Other (not listed)" is the catch-all a clerk picks when no PSIC
     * code fits. It carries seven businesses and every one of them is new, so it
     * scores +7 — enough to outrank a 26-business furniture trade that genuinely
     * grew by six. Without a floor, the fastest-growing thing in the city is a
     * data-entry default.
     *
     * The floor removes it from the ranking. It does NOT remove it from the
     * register, and Largest still ranks purely by size, so nothing here is
     * hidden — it is only kept out of a comparison it is too small to inform.
     */
    $lenses = BusinessGrowthAnalytics::computeIndustryLenses([
        industryFact('00000', count: 7, registrations: 7, prior: 0),
        industryFact('31001', count: 26, registrations: 10, prior: 4),
        industryFact('10500', count: 1, registrations: 1, prior: 0),
    ], slots: 6, minBusinesses: 10);

    expect($lenses['min_businesses'])->toBe(10);
    expect($lenses['lines_on_record'])->toBe(3);
    expect($lenses['above_floor'])->toBe(1);

    $growing = lensNamed($lenses, 'growing');
    expect(array_column($growing['rows'], 'psic_code'))->toBe(['31001']);
    expect($growing['qualifying'])->toBe(1);
    expect($growing['floored'])->toBeTrue();

    // Largest is unfloored and still sees all three, biggest first.
    $largest = lensNamed($lenses, 'largest');
    expect(array_column($largest['rows'], 'psic_code'))->toBe(['31001', '00000', '10500']);
    expect($largest['floored'])->toBeFalse();
});

it('ranks growing by the biggest increase and declining by the biggest fall', function () {
    $lenses = BusinessGrowthAnalytics::computeIndustryLenses([
        industryFact('47111', count: 48, registrations: 30, prior: 6),   // +24
        industryFact('56101', count: 44, registrations: 20, prior: 4),   // +16
        industryFact('45201', count: 32, registrations: 7, prior: 12),   // -5
        industryFact('18120', count: 38, registrations: 11, prior: 13),  // -2
        industryFact('47721', count: 30, registrations: 10, prior: 10),  //  0
    ], slots: 6, minBusinesses: 10);

    expect(array_column(lensNamed($lenses, 'growing')['rows'], 'psic_code'))
        ->toBe(['47111', '56101']);

    expect(array_column(lensNamed($lenses, 'declining')['rows'], 'psic_code'))
        ->toBe(['45201', '18120']);

    /*
     * A line that did not move belongs to neither. It is the cheapest possible
     * way to fill six slots and it would put a flat trade under a heading that
     * says it is declining, which is a finding the register does not support.
     */
    foreach (['growing', 'declining'] as $key) {
        expect(array_column(lensNamed($lenses, $key)['rows'], 'psic_code'))
            ->not->toContain('47721');
    }
});

it('draws what there is when fewer than six lines qualify, and counts them honestly', function () {
    // Two lines declined. The lens shows two, and `qualifying` says two — which
    // is what lets the screen write "only 2 industries declined" instead of
    // leaving a reader to wonder what happened to the other four.
    $lenses = BusinessGrowthAnalytics::computeIndustryLenses([
        industryFact('45201', count: 32, registrations: 7, prior: 12),
        industryFact('18120', count: 38, registrations: 11, prior: 13),
        industryFact('47111', count: 48, registrations: 30, prior: 6),
    ], slots: 6, minBusinesses: 10);

    $declining = lensNamed($lenses, 'declining');
    expect($declining['qualifying'])->toBe(2);
    expect($declining['rows'])->toHaveCount(2);
});

it('leaves a lens empty rather than filling it from the wrong side of zero', function () {
    $lenses = BusinessGrowthAnalytics::computeIndustryLenses([
        industryFact('47111', count: 48, registrations: 30, prior: 6),
    ], slots: 6, minBusinesses: 10);

    $declining = lensNamed($lenses, 'declining');
    expect($declining['rows'])->toBe([]);
    expect($declining['qualifying'])->toBe(0);
});

it('never hands the chart more series than the palette can keep apart', function () {
    // Six colours, six dash patterns, six slots. A seventh series would repeat a
    // colour, and "Never Color Alone" only holds while the pairs are unique.
    $facts = [];
    foreach (range(1, 20) as $i) {
        $facts[] = industryFact(sprintf('%05d', $i), count: 100 - $i, registrations: 50 - $i, prior: 0);
    }

    $lenses = BusinessGrowthAnalytics::computeIndustryLenses($facts, slots: 6, minBusinesses: 10);

    foreach ($lenses['lenses'] as $lens) {
        expect(count($lens['rows']))->toBeLessThanOrEqual(6, "The {$lens['key']} lens overflowed the palette.");
    }
    expect(lensNamed($lenses, 'growing')['qualifying'])->toBe(20);
});

it('serves the lenses beside the stored panel, without touching it', function () {
    /*
     * The splice AnalyticsController makes. Two halves matter:
     *
     *  - `industry_lenses` reaches the browser at all. It cannot ride on the
     *    snapshot, because AnalyticsResolver serves a stored payload verbatim —
     *    a key added to the builder would not appear until the next refresh, and
     *    until then the screen would keep drawing last night's rows without it.
     *  - `industry_growth` is untouched. The PDF report and the golden fixture
     *    both read it, and the Largest lens reproduces its ranking rule exactly,
     *    so on one register read the two agree row for row.
     */
    $data = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('industry_growth');
    expect($data['industry_lenses'])->toHaveKeys([
        'slots', 'min_businesses', 'lines_on_record', 'above_floor', 'lenses',
    ]);

    expect($data['industry_lenses']['min_businesses'])
        ->toBe(BusinessGrowthAnalytics::INDUSTRY_LENS_MIN_BUSINESSES);

    expect(array_column($data['industry_lenses']['lenses'], 'key'))
        ->toBe(['largest', 'growing', 'declining']);

    expect(lensNamed($data['industry_lenses'], 'largest')['rows'])
        ->toBe($data['industry_growth']);

    // Every row the chart may draw carries what the chart and its hidden table
    // need, whichever lens produced it.
    foreach ($data['industry_lenses']['lenses'] as $lens) {
        expect(count($lens['rows']))->toBeLessThanOrEqual($data['industry_lenses']['slots']);

        foreach ($lens['rows'] as $row) {
            expect($row)->toHaveKeys([
                'industry', 'psic_code', 'count', 'registrations', 'prior', 'delta', 'direction',
            ]);
            expect($row['delta'])->toBe($row['registrations'] - $row['prior']);

            if ($lens['floored']) {
                expect($row['count'])->toBeGreaterThanOrEqual(
                    $data['industry_lenses']['min_businesses'],
                    "The {$lens['key']} lens ranked a line below the stated minimum.",
                );
            }
        }
    }

    expect(array_column(lensNamed($data['industry_lenses'], 'growing')['rows'], 'direction'))
        ->each->toBe('growing');
    expect(array_column(lensNamed($data['industry_lenses'], 'declining')['rows'], 'direction'))
        ->each->toBe('declining');
});
