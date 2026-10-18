<?php

use App\Support\CustomReports;
use Illuminate\Support\Facades\DB;

/*
 * The Custom Report builder (client, 19 October 2026).
 *
 * Pinned here: every subject counts what the rest of analytics counts (drafts
 * and removed records out), filters and breakdowns narrow and split correctly,
 * the Total row is honest when a business has several lines of business, the
 * office boundary is Reports' own, the definition is a whitelist (anything
 * unknown is a 422), saved set-ups are the reader's own, and every export
 * answers.
 *
 * Isolation without deleting seeded rows (tests/AnalyticsFixtures.php): the
 * business tests filter on PSIC codes of their own, and the dated subjects
 * use 2019, a year the demo seed (which dates relative to the clock) never
 * writes into.
 */

const CR_YEAR = ['from' => '2019-01-01', 'to' => '2019-12-31'];

function crRun(string $email, array $definition, string $office = ''): array
{
    return test()->withHeaders(authAs($email))
        ->postJson('/api/v1/analytics/custom-reports/run'.($office === '' ? '' : "?office={$office}"), ['definition' => $definition])
        ->assertOk()
        ->json('data');
}

function crPsic(string $code, ?string $category = 'retailer'): int
{
    return DB::table('psic_codes')->insertGetId([
        'code' => $code, 'title' => "Test line {$code}", 'category' => $category,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function crLine(int $businessId, int $psicId, ?float $capitalization = null, ?float $gross = null): void
{
    DB::table('business_lines')->insert([
        'business_id' => $businessId, 'psic_code_id' => $psicId,
        'capitalization' => $capitalization, 'gross_sales' => $gross,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function crPlace(int $businessId, int $barangayId): void
{
    DB::table('business_addresses')->insert([
        'business_id' => $businessId, 'line1' => '1 Test St', 'barangay_id' => $barangayId,
        'address_type' => 'business_location', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A business on the register (it has a filing past draft), with lines and a place. */
function crBusiness(array $attrs, array $psicIds, ?int $barangayId = null): int
{
    $id = anaBusiness($attrs);
    anaFiling($id, ['status' => 'approved', 'submitted_at' => '2019-06-01 10:00:00']);
    foreach ($psicIds as $psic) {
        crLine($id, $psic);
    }
    if ($barangayId !== null) {
        crPlace($id, $barangayId);
    }

    return $id;
}

function crBarangays(int $n): array
{
    return DB::table('barangays')->orderBy('name')->limit($n)->pluck('name', 'id')->all();
}

function crPayment(int $applicationId, array $lines, float $amount, string $paidAt, string $status = 'completed'): int
{
    $assessment = DB::table('fee_assessments')->insertGetId([
        'application_id' => $applicationId,
        'line_items' => json_encode($lines),
        'total_amount' => array_sum(array_column($lines, 'amount')),
        'created_at' => $paidAt, 'updated_at' => $paidAt,
    ]);

    return DB::table('payments')->insertGetId([
        'application_id' => $applicationId, 'fee_assessment_id' => $assessment,
        'amount' => $amount, 'method' => 'counter', 'status' => $status,
        'paid_at' => $status === 'completed' ? $paidAt : null,
        'created_at' => $paidAt, 'updated_at' => $paidAt,
    ]);
}

/* ── the catalogue and the permission ─────────────────────────────────── */

it('offers four subjects, plain labels and the starters to every Reports reader', function () {
    $body = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/custom-reports/catalogue')
        ->assertOk()
        ->json();

    expect(array_column($body['data']['subjects'], 'key'))->toBe(['businesses', 'applications', 'permits', 'payments'])
        ->and(array_column($body['data']['starters'], 'title'))->toContain('Employees by sex', 'Businesses per barangay', 'Collections per month', 'Permits issued per type')
        ->and($body['scope']['office'])->toBe('BPLO');

    $businesses = $body['data']['subjects'][0];
    expect(array_column($businesses['measures'], 'label'))->toContain('Male employees', 'Female employees', 'Employees living in Malabon')
        // No column names leak into the labels.
        ->and(implode(' ', array_column($businesses['measures'], 'label')))->not->toContain('_');
});

it('refuses the builder to a reader without analytics.view, and another office to an office admin', function () {
    $definition = ['subject' => 'permits', 'measures' => ['count']];

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/analytics/custom-reports/catalogue')->assertForbidden();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/analytics/custom-reports/run', ['definition' => $definition])->assertForbidden();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/analytics/custom-reports/saved')->assertForbidden();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/analytics/custom-reports/run?office=BFP', ['definition' => $definition])->assertForbidden();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/analytics/custom-reports/export/csv?office=all', ['definition' => $definition])->assertForbidden();
});

/* ── businesses ───────────────────────────────────────────────────────── */

it('counts businesses and sums their employees per barangay, with every barangay listed', function () {
    $psic = crPsic('ZZ001');
    [$b1, $b2] = array_keys(crBarangays(2));
    crBusiness(['total_employees' => 10, 'male_employees' => 6, 'female_employees' => 4], [$psic], $b1);
    crBusiness(['total_employees' => 5, 'male_employees' => 1, 'female_employees' => 4], [$psic], $b1);
    crBusiness(['total_employees' => 3, 'male_employees' => 3, 'female_employees' => 0], [$psic], $b2);

    $report = crRun('bplo@biztrack.local', [
        'subject' => 'businesses',
        'measures' => ['count', 'male_employees', 'female_employees', 'total_employees'],
        'filters' => ['psic' => [(string) $psic]],
        'breakdown' => ['barangay'],
    ], 'all');

    $byName = array_column($report['rows'], null, 'by1');
    $names = crBarangays(2);
    expect($byName[$names[$b1]])->toMatchArray(['count' => 2, 'male_employees' => 7, 'female_employees' => 8, 'total_employees' => 15])
        ->and($byName[$names[$b2]])->toMatchArray(['count' => 1, 'male_employees' => 3, 'female_employees' => 0, 'total_employees' => 3])
        ->and($report['total'])->toMatchArray(['by1' => 'Total', 'count' => 3, 'male_employees' => 10, 'female_employees' => 8, 'total_employees' => 18])
        // Every barangay is a row, a barangay with no business at zero.
        ->and(count($report['rows']))->toBe(DB::table('barangays')->count());

    $empty = collect($report['rows'])->first(fn ($r) => $r['count'] === 0);
    // A sum over no business is a dash (null), never a zero.
    expect($empty['male_employees'])->toBeNull();
});

it('leaves out removed businesses and businesses that never got past a draft', function () {
    $psic = crPsic('ZZ002');
    crBusiness(['total_employees' => 4], [$psic]);
    $removed = crBusiness(['total_employees' => 50], [$psic]);
    DB::table('businesses')->where('id', $removed)->update(['deleted_at' => now()]);
    $draftOnly = anaBusiness(['total_employees' => 70]);
    anaFiling($draftOnly, ['status' => 'draft']);
    crLine($draftOnly, $psic);

    $report = crRun('bplo@biztrack.local', [
        'subject' => 'businesses', 'measures' => ['count', 'total_employees'],
        'filters' => ['psic' => [(string) $psic]],
    ], 'all');

    expect($report['total'])->toMatchArray(['count' => 1, 'total_employees' => 4])
        ->and($report['rows'])->toBe([]);
});

it('never double-counts a business with several lines of business', function () {
    $a = crPsic('ZZ003', 'retailer');
    $b = crPsic('ZZ004', 'retailer');
    $id = crBusiness(['total_employees' => 12, 'male_employees' => 5], [$a, $b]);
    DB::table('business_lines')->where('business_id', $id)->where('psic_code_id', $a)->update(['capitalization' => 1000, 'gross_sales' => 40]);
    DB::table('business_lines')->where('business_id', $id)->where('psic_code_id', $b)->update(['capitalization' => 500, 'gross_sales' => 60]);
    crBusiness(['total_employees' => 3, 'male_employees' => 3], [$a]);

    $filters = ['psic' => [(string) $a, (string) $b]];

    // Filtering on both lines keeps each business once.
    $filtered = crRun('bplo@biztrack.local', [
        'subject' => 'businesses', 'measures' => ['count', 'total_employees', 'capitalization'], 'filters' => $filters,
    ], 'all');
    expect($filtered['total'])->toMatchArray(['count' => 2, 'total_employees' => 15, 'capitalization' => 1500.0]);

    // Broken down by line, the two-line business is under each line, and
    // the Total still counts it once.
    $byLine = crRun('bplo@biztrack.local', [
        'subject' => 'businesses', 'measures' => ['count', 'total_employees', 'capitalization', 'gross_sales'],
        'filters' => $filters, 'breakdown' => ['psic'],
    ], 'all');
    $rows = array_column($byLine['rows'], null, 'by1');
    expect($rows['ZZ003 — Test line ZZ003'])->toMatchArray(['count' => 2, 'total_employees' => 15, 'capitalization' => 1000.0, 'gross_sales' => 40.0])
        ->and($rows['ZZ004 — Test line ZZ004'])->toMatchArray(['count' => 1, 'total_employees' => 12, 'capitalization' => 500.0, 'gross_sales' => 60.0])
        ->and($byLine['total'])->toMatchArray(['count' => 2, 'total_employees' => 15, 'capitalization' => 1500.0, 'gross_sales' => 100.0])
        ->and(implode(' ', $byLine['notes']))->toContain('counted under each');

    // Two lines of the same kind put the business under that kind once.
    $byKind = crRun('bplo@biztrack.local', [
        'subject' => 'businesses', 'measures' => ['count', 'total_employees'], 'filters' => $filters, 'breakdown' => ['kind'],
    ], 'all');
    $retailer = collect($byKind['rows'])->firstWhere('by1', 'Retailer');
    expect($retailer)->toMatchArray(['count' => 2, 'total_employees' => 15]);
});

it('filters and breaks down businesses by owner’s sex, form and a valid permit', function () {
    $psic = crPsic('ZZ005');
    $woman = crBusiness(['form_of_organization' => 'sole_proprietorship', 'has_tax_incentives' => true], [$psic]);
    DB::table('business_owners')->insert(['business_id' => $woman, 'gender' => 'F', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    $man = crBusiness(['form_of_organization' => 'corporation'], [$psic]);
    DB::table('business_owners')->insert(['business_id' => $man, 'gender' => 'M', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    anaPermit($man, 'BUSINESS', ['issued_at' => '2019-06-02 10:00:00']);

    $base = ['subject' => 'businesses', 'measures' => ['count'], 'filters' => ['psic' => [(string) $psic]]];

    $bySex = crRun('bplo@biztrack.local', $base + ['breakdown' => ['owner_sex']], 'all');
    expect(array_column($bySex['rows'], 'count', 'by1'))->toBe(['Male' => 1, 'Female' => 1]);

    $sec = crRun('bplo@biztrack.local', ['filters' => ['psic' => [(string) $psic], 'registrar' => ['SEC']]] + $base, 'all');
    expect($sec['total']['count'])->toBe(1);

    $incentives = crRun('bplo@biztrack.local', ['filters' => ['psic' => [(string) $psic], 'tax_incentives' => ['1']]] + $base, 'all');
    expect($incentives['total']['count'])->toBe(1);

    $standing = crRun('bplo@biztrack.local', $base + ['breakdown' => ['standing']], 'all');
    expect(array_column($standing['rows'], 'count', 'by1'))->toBe(['Yes' => 1, 'No' => 1]);
});

/* ── applications ─────────────────────────────────────────────────────── */

it('counts filings past draft, by type and month, inside the dates', function () {
    $biz = anaBusiness();
    anaFiling($biz, ['submitted_at' => '2019-03-05 09:00:00', 'application_type' => 'new']);
    anaFiling($biz, ['submitted_at' => '2019-03-20 09:00:00', 'application_type' => 'renewal']);
    anaFiling($biz, ['submitted_at' => '2019-05-01 09:00:00', 'application_type' => 'renewal']);
    anaFiling($biz, ['submitted_at' => '2019-05-02 09:00:00', 'status' => 'draft']);
    $removed = anaFiling($biz, ['submitted_at' => '2019-05-03 09:00:00']);
    DB::table('applications')->where('id', $removed)->update(['deleted_at' => now()]);
    anaFiling($biz, ['submitted_at' => '2020-01-02 09:00:00']);

    $report = crRun('bplo@biztrack.local', [
        'subject' => 'applications', 'measures' => ['count', 'businesses'],
        'period' => CR_YEAR, 'breakdown' => ['month', 'app_type'],
    ], 'all');

    $rows = array_map(fn ($r) => [$r['by1'], $r['by2'], $r['count']], $report['rows']);
    expect($rows)->toBe([
        ['March 2019', 'New', 1],
        ['March 2019', 'Renewal', 1],
        ['May 2019', 'Renewal', 1],
    ])
        ->and($report['total'])->toMatchArray(['count' => 3, 'businesses' => 1])
        ->and(implode(' ', $report['notes']))->toContain('the total counts it once');
});

it('lists every month of the period for a one-level month breakdown', function () {
    anaFiling(anaBusiness(), ['submitted_at' => '2019-02-10 09:00:00']);

    $report = crRun('bplo@biztrack.local', [
        'subject' => 'applications', 'measures' => ['count'], 'period' => CR_YEAR, 'breakdown' => ['month'],
    ], 'all');

    expect(count($report['rows']))->toBe(12)
        ->and($report['rows'][1])->toMatchArray(['by1' => 'February 2019', 'count' => 1])
        ->and($report['rows'][0])->toMatchArray(['by1' => 'January 2019', 'count' => 0]);
});

it('counts only the filings routed to an office for that office', function () {
    $biz = anaBusiness();
    $mine = anaFiling($biz, ['submitted_at' => '2019-04-01 09:00:00']);
    anaAssignment($mine, 'CHO');
    $theirs = anaFiling($biz, ['submitted_at' => '2019-04-02 09:00:00']);
    anaAssignment($theirs, 'BFP');

    $definition = ['subject' => 'applications', 'measures' => ['count'], 'period' => CR_YEAR];
    expect(crRun('sanitary@biztrack.local', $definition)['total']['count'])->toBe(1)
        ->and(crRun('sanitary@biztrack.local', $definition)['scope']['office'])->toBe('CHO')
        ->and(crRun('bplo@biztrack.local', $definition, 'all')['total']['count'])->toBe(2);
});

/* ── permits ──────────────────────────────────────────────────────────── */

it('counts permits issued per type, and only the office’s own types for an office', function () {
    $biz = anaBusiness();
    anaPermit($biz, 'SANITARY', ['issued_at' => '2019-07-01 09:00:00']);
    anaPermit($biz, 'SANITARY', ['issued_at' => '2019-08-01 09:00:00', 'status' => 'revoked']);
    anaPermit($biz, 'FSIC', ['issued_at' => '2019-07-01 09:00:00']);
    anaPermit($biz, 'FSIC', ['issued_at' => null]);

    $definition = ['subject' => 'permits', 'measures' => ['count'], 'period' => CR_YEAR, 'breakdown' => ['permit_type']];

    $all = crRun('bplo@biztrack.local', $definition, 'all');
    $byType = array_column($all['rows'], 'count', 'by1');
    $sanitaryName = DB::table('permit_types')->where('code', 'SANITARY')->value('name');
    $fsicName = DB::table('permit_types')->where('code', 'FSIC')->value('name');
    expect($byType[$sanitaryName])->toBe(2)
        ->and($byType[$fsicName])->toBe(1)
        ->and($all['total']['count'])->toBe(3);

    $cho = crRun('sanitary@biztrack.local', $definition);
    expect(array_column($cho['rows'], 'by1'))->toBe([$sanitaryName])
        ->and($cho['total']['count'])->toBe(2);

    // A permit type the office does not issue is not a choice it can filter on.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/analytics/custom-reports/run', ['definition' => [
            'subject' => 'permits', 'measures' => ['count'],
            'filters' => ['permit_type' => [(string) DB::table('permit_types')->where('code', 'FSIC')->value('id')]],
        ]])->assertStatus(422);
});

it('reconciles “Permits issued per type” with the fixed Permits Issued report', function () {
    $from = now()->subYear()->toDateString();
    $to = now()->toDateString();

    $custom = crRun('bplo@biztrack.local', [
        'subject' => 'permits', 'measures' => ['count'], 'period' => ['from' => $from, 'to' => $to], 'breakdown' => ['permit_type'],
    ], 'all');
    $fixed = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/analytics/reports/permits-issued?from={$from}&to={$to}&office=all")
        ->assertOk()->json('data.sections.1');

    expect($custom['total']['count'])->toBeGreaterThan(0)
        ->and($custom['total']['count'])->toBe($fixed['total']['total']);
});

/* ── payments ─────────────────────────────────────────────────────────── */

it('sums completed payments, and credits an office only its own fee lines', function () {
    $filing = anaFiling(anaBusiness(), ['submitted_at' => '2019-01-10 09:00:00']);
    crPayment($filing, [
        ['office' => 'CHO', 'amount' => 300, 'group' => 'regulatory'],
        ['office' => 'BPLO', 'amount' => 700, 'group' => 'mayors_permit'],
    ], 1000, '2019-02-01 10:00:00');
    crPayment($filing, [['office' => 'BPLO', 'amount' => 200]], 200, '2019-03-01 10:00:00');
    crPayment($filing, [['office' => 'CHO', 'amount' => 999]], 999, '2019-03-02 10:00:00', 'failed');

    $definition = ['subject' => 'payments', 'measures' => ['count', 'amount'], 'period' => CR_YEAR, 'breakdown' => ['month']];

    $all = crRun('bplo@biztrack.local', $definition, 'all');
    expect($all['total'])->toMatchArray(['count' => 2, 'amount' => 1200.0])
        ->and(collect($all['rows'])->firstWhere('by1', 'February 2019'))->toMatchArray(['count' => 1, 'amount' => 1000.0]);

    $cho = crRun('sanitary@biztrack.local', $definition);
    expect($cho['total'])->toMatchArray(['count' => 1, 'amount' => 300.0])
        ->and(collect($cho['rows'])->firstWhere('by1', 'February 2019'))->toMatchArray(['count' => 1, 'amount' => 300.0])
        ->and(collect($cho['rows'])->firstWhere('by1', 'March 2019'))->toMatchArray(['count' => 0, 'amount' => null]);
});

it('says plainly when nothing matches', function () {
    $report = crRun('bplo@biztrack.local', ['subject' => 'payments', 'measures' => ['count'], 'period' => CR_YEAR], 'all');

    expect($report['matched'])->toBe(0)
        ->and($report['empty'])->toBe('No payments match these filters.');
});

/* ── the definition is a whitelist ────────────────────────────────────── */

it('answers anything outside the whitelist with a short 422', function (array $definition) {
    $response = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/analytics/custom-reports/run', ['definition' => $definition])
        ->assertStatus(422);

    $message = collect($response->json('errors'))->flatten()->first();
    expect(mb_strlen($message))->toBeLessThan(50);
})->with([
    'no subject' => [['measures' => ['count']]],
    'unknown subject' => [['subject' => 'owners', 'measures' => ['count']]],
    'unknown top-level key' => [['subject' => 'permits', 'measures' => ['count'], 'sql' => 'drop table users']],
    'no measures' => [['subject' => 'permits', 'measures' => []]],
    'measure of another subject' => [['subject' => 'permits', 'measures' => ['male_employees']]],
    'raw SQL as a measure' => [['subject' => 'permits', 'measures' => ['count(*)']]],
    'filter not for the subject' => [['subject' => 'businesses', 'measures' => ['count'], 'filters' => ['permit_type' => ['1']]]],
    'unknown filter value' => [['subject' => 'businesses', 'measures' => ['count'], 'filters' => ['form' => ["x' or 1=1"]]]],
    'empty filter' => [['subject' => 'businesses', 'measures' => ['count'], 'filters' => ['form' => []]]],
    'dates on businesses' => [['subject' => 'businesses', 'measures' => ['count'], 'period' => CR_YEAR]],
    'bad date' => [['subject' => 'permits', 'measures' => ['count'], 'period' => ['from' => '01/02/2019']]],
    'dates backwards' => [['subject' => 'permits', 'measures' => ['count'], 'period' => ['from' => '2019-05-01', 'to' => '2019-01-01']]],
    'three breakdowns' => [['subject' => 'applications', 'measures' => ['count'], 'breakdown' => ['month', 'app_type', 'barangay']]],
    'same breakdown twice' => [['subject' => 'applications', 'measures' => ['count'], 'breakdown' => ['month', 'month']]],
    'line and kind together' => [['subject' => 'businesses', 'measures' => ['count'], 'breakdown' => ['psic', 'kind']]],
    'month as a filter' => [['subject' => 'permits', 'measures' => ['count'], 'filters' => ['month' => ['2019-01']]]],
]);

it('runs every starter as it is offered', function () {
    foreach (CustomReports::starters() as $starter) {
        $report = crRun('bplo@biztrack.local', $starter['definition'], 'all');
        expect($report['title'])->toBeString()->and($report['total']['by1'])->toBe('Total');
    }
});

/* ── saved set-ups ────────────────────────────────────────────────────── */

it('saves, lists, renames and deletes a set-up, for its owner only', function () {
    $definition = ['subject' => 'businesses', 'measures' => ['count'], 'breakdown' => ['barangay']];
    $as = fn (string $email) => test()->withHeaders(authAs($email));

    $id = $as('bplo@biztrack.local')
        ->postJson('/api/v1/analytics/custom-reports/saved', ['name' => '  Per   barangay ', 'definition' => $definition])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Per barangay')
        ->json('data.id');

    $as('bplo@biztrack.local')
        ->postJson('/api/v1/analytics/custom-reports/saved', ['name' => 'Per barangay', 'definition' => $definition])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', 'You already have a report by that name.');
    $as('bplo@biztrack.local')
        ->postJson('/api/v1/analytics/custom-reports/saved', ['name' => 'Broken', 'definition' => ['subject' => 'x']])
        ->assertStatus(422);

    expect($as('bplo@biztrack.local')->getJson('/api/v1/analytics/custom-reports/saved')->json('data.*.name'))->toBe(['Per barangay'])
        ->and($as('admin@biztrack.local')->getJson('/api/v1/analytics/custom-reports/saved')->json('data'))->toBe([]);

    // Somebody else's set-up does not exist, for reading, renaming or deleting.
    $as('admin@biztrack.local')->patchJson("/api/v1/analytics/custom-reports/saved/{$id}", ['name' => 'Mine now'])->assertNotFound();
    $as('admin@biztrack.local')->deleteJson("/api/v1/analytics/custom-reports/saved/{$id}")->assertNotFound();

    $as('bplo@biztrack.local')->patchJson("/api/v1/analytics/custom-reports/saved/{$id}", ['name' => 'Barangays'])
        ->assertOk()->assertJsonPath('data.name', 'Barangays');
    $as('bplo@biztrack.local')->deleteJson("/api/v1/analytics/custom-reports/saved/{$id}")->assertOk();
    expect(DB::table('saved_reports')->where('id', $id)->exists())->toBeFalse();
});

/* ── exports ──────────────────────────────────────────────────────────── */

it('exports the same figures as CSV, Excel and PDF, named for the office', function () {
    $psic = crPsic('ZZ006');
    crBusiness(['total_employees' => 9, 'citizenship' => '=HYPERLINK("x")'], [$psic]);
    $definition = ['subject' => 'businesses', 'measures' => ['count', 'total_employees'], 'filters' => ['psic' => [(string) $psic]], 'breakdown' => ['citizenship']];
    $as = fn () => test()->withHeaders(authAs('bplo@biztrack.local'));
    $today = now()->setTimezone('Asia/Manila')->toDateString();

    $csv = $as()->postJson('/api/v1/analytics/custom-reports/export/csv?office=all', ['definition' => $definition])->assertOk();
    $body = $csv->streamedContent();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->headers->get('content-disposition'))->toContain("custom-report-businesses-{$today}.csv")
        ->and($body)->toContain('Total,1,9')
        // An owner-typed value is never a live formula in the spreadsheet.
        ->and($body)->toContain("'=hyperlink");

    $xlsx = $as()->postJson('/api/v1/analytics/custom-reports/export/xlsx?office=CHO', ['definition' => $definition])->assertOk();
    $bytes = $xlsx->getContent();
    expect(substr($bytes, 0, 2))->toBe('PK')
        ->and($xlsx->headers->get('content-disposition'))->toContain('custom-report-businesses-cho-');
    $path = tempnam(sys_get_temp_dir(), 'xl');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Total employees');
    $zip->close();
    @unlink($path);

    $pdf = $as()->postJson('/api/v1/analytics/custom-reports/export/pdf?office=all', ['definition' => $definition])->assertOk();
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and($pdf->headers->get('content-type'))->toBe('application/pdf');

    $as()->postJson('/api/v1/analytics/custom-reports/export/docx', ['definition' => $definition])->assertNotFound();
});
