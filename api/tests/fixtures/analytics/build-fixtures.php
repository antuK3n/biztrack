<?php

/*
 * Builds the dashboard fixture DATASET. (The growth/lifecycle one went with
 * Business Growth Analysis, checklist 2026-09-27 item 6.)
 *
 * These are not dumps of the register. Every row is here because it sits on a
 * branch where a statistic could plausibly come out wrong — a null that must not
 * become a zero, an exact half that exposes the rounding mode, a denominator the
 * register cannot complete. Values are arithmetic, never random, so the files are
 * byte-stable across machines.
 *
 *   php tests/fixtures/analytics/build-fixtures.php
 *
 * ── ABOUT THE `.expected.json` FILES ────────────────────────────────────────
 *
 * They were originally `.r-output.json`: R was the reference implementation, and
 * the goldens were captured by POSTing these datasets to the plumber service.
 * AnalyticsParityTest then held the PHP port to them value for value.
 *
 * R has been removed. The goldens were not regenerated, because they did not
 * need to be — the parity test passed on every run up to the removal, so those
 * files already ARE the PHP builders' output, and keeping the bytes means the
 * baseline still pins the numbers a user has actually seen. They were renamed and
 * nothing else. See tests/Unit/AnalyticsGoldenOutputTest.php.
 *
 * To re-baseline after a deliberate change to a builder, dump `compute()`'s
 * output over the dataset file and write it back, e.g.:
 *
 *   cd api && php -r '
 *     $d = json_decode(file_get_contents("tests/fixtures/analytics/dashboard.dataset.json"), true);
 *     require "vendor/autoload.php";
 *     echo json_encode(App\Support\DashboardAnalytics::compute($d), JSON_PRETTY_PRINT);
 *   ' > tests/fixtures/analytics/dashboard.expected.json
 *
 * Do that only when the change was intended and is described in the commit. The
 * whole value of the baseline is that re-baselining is a deliberate act.
 *
 * RE-BASELINED 18 October 2026, deliberately (the client's dashboard picker):
 * the tier panel, the RA 11032 compliance row, the Cancelled row, response
 * time, meetings and the renewal forecast left the payload; Time in Office,
 * Fees Collected, Suspended Permits, `applications_all_time` and
 * `schema_version` joined it. The old baseline also named BPLO the slowest
 * office at 0.1 days above the average — the callout the client asked to
 * drop. Every other figure in dashboard.expected.json is unchanged.
 *
 * RE-BASELINED 19 October 2026, deliberately (the client's picker again):
 * Messages & Requests (`officer_activity`) left the payload and
 * `schema_version` went to 3. Every other figure is unchanged.
 * Written with two-space indents, as the R-era file was.
 */

$dir = __DIR__;

/* ── dashboard ────────────────────────────────────────────────────────── */

/*
 * The tier observations that stood here went with the RA 11032 tier panel on
 * 18 October 2026 (it rested on a complexity tier BPLO no longer sets). Their
 * rounding traps — 4.25 → 4.2 half-to-even, an exact 6.5 — are kept alive by
 * the Time in Office means below and by the processing-time fixture.
 */

/*
 * Time in Office observations, in WORKING days (whole numbers, as
 * ManilaCalendar counts them). BPLO and CHO differ, then three offices tie on
 * BOTH mean (0 days) and permit count (1) — the shape that needs the code
 * tie-break. Codes are given out of alphabetical order so a stable sort cannot
 * pass by accident.
 *
 * CHO at 5.0 over 3 permits against an overall 21/9 = 2.33 → 2.3 is 2.7 days
 * and over a quarter above: it is NAMED the slowest office. BPLO, drawn at 2.0,
 * would not be. The not-named branches are pinned in AnalyticsDepartmentTimeTest.
 */
$stageObservations = [];
foreach ([1, 2, 3] as $days) {
    $stageObservations[] = ['code' => 'BPLO', 'name' => 'Business Permits and Licensing Office', 'days' => $days];
}
foreach ([4, 5, 6] as $days) {
    $stageObservations[] = ['code' => 'CHO', 'name' => 'City Health Office', 'days' => $days];
}
foreach ([['OBO', 'Office of the Local Building Official'], ['CENRO', 'City Environment Office'], ['BFP', 'Bureau of Fire Protection']] as [$code, $name]) {
    $stageObservations[] = ['code' => $code, 'name' => $name, 'days' => 0];
}

/*
 * Expiry rows. One permit in every cumulative band so the nesting is actually
 * exercised: 10 days out counts in 30/60/90, 45 days out in 60/90 only, 80 days
 * in 90 only, and -5 days in Expired alone. ZONING appears only as an expired
 * permit, so a column that is zero in three rows still has to render.
 */
$expiringPermits = [];
foreach ([['BUSINESS', 10], ['BUSINESS', 45], ['BUSINESS', 80], ['BUSINESS', -5],
    ['SANITARY', 10], ['SANITARY', 80], ['FSIC', 45], ['FSIC', -5],
    ['ZONING', -5], ['ZONING', -40]] as [$code, $days]) {
    $expiringPermits[] = ['code' => $code, 'days_to_expiry' => $days];
}

/*
 * Barangay counts totalling 16, so a count of 1 gives a 6.25% share — an exact
 * half at one decimal place, rounding to 6.2 under both engines' half-to-even.
 * Bulacan and Acacia TIE on 4, which forces the name tie-break; Acacia must rank
 * first, and it is listed second here so input order cannot supply the answer.
 */
$barangays = [
    ['barangay' => 'Longos', 'count' => 7],
    ['barangay' => 'Bulacan', 'count' => 4],
    ['barangay' => 'Acacia', 'count' => 4],
    ['barangay' => 'Tinajeros', 'count' => 1],
];

$linesOfBusiness = [
    ['industry' => 'Retail sale of hardware', 'psic_code' => '47521', 'count' => 5],
    // Another exact tie, resolved on the industry name: "Bakery" before "Laundry".
    ['industry' => 'Laundry services', 'psic_code' => '96200', 'count' => 3],
    ['industry' => 'Bakery products', 'psic_code' => '10711', 'count' => 3],
];

/*
 * Forms of organization POPULATED, with a remainder that has none on file. The
 * live register hits the all-blank branch, so the fixture covers the other one:
 * shares must be of the 20 recorded, not the 25 total, or the rows will not sum
 * to 100%. 5/20 is 25% exactly and 1/20 is 5%.
 */
$organizationForms = [
    'forms' => [
        ['form' => 'sole_proprietorship', 'label' => 'Sole Proprietorship', 'count' => 12],
        ['form' => 'corporation', 'label' => 'Corporation', 'count' => 5],
        ['form' => 'partnership', 'label' => 'Partnership', 'count' => 2],
        ['form' => 'cooperative', 'label' => 'Cooperative', 'count' => 1],
    ],
    'unrecorded' => 5,
    'total' => 25,
];

/*
 * Inspections: one type whose pass rate computes, one with a failure so the
 * failed column is not always zero, and one SCHEDULED BUT NEVER COMPLETED — whose
 * pass rate must be null rather than 0%, and which would read as 0% if anyone
 * divided by scheduled instead of completed.
 */
$inspections = [
    ['type' => 'CHO', 'label' => 'Sanitary', 'scheduled' => 10, 'completed' => 8, 'passed' => 7, 'failed' => 0, 'conditional' => 1],
    ['type' => 'BFP', 'label' => 'Fire Safety', 'scheduled' => 9, 'completed' => 8, 'passed' => 5, 'failed' => 2, 'conditional' => 1],
    ['type' => 'CPDO', 'label' => 'Zoning', 'scheduled' => 4, 'completed' => 0, 'passed' => 0, 'failed' => 0, 'conditional' => 0],
];

/*
 * Fees Collected. Values at two decimals whose sums would drift in binary
 * floating point (0.10 + 0.20 sums to 0.30000000000000004) so the totals must
 * be rounded, a type with nothing collected that must keep its zero row, and
 * an Other row. Totals: month 1000.10 + 0.20 + 0 + 250.00 + 0 + 0 + 49.95 =
 * 1300.25; year adds the February FSIC 500.
 */
$fees = [
    'month_start' => '2026-07-01',
    'year_start' => '2026-01-01',
    'types' => [
        ['code' => 'BUSINESS', 'label' => "Mayor's / Business Permit"],
        ['code' => 'SANITARY', 'label' => 'Sanitary Permit'],
        ['code' => 'FSIC', 'label' => 'Fire Safety Inspection Certificate'],
        ['code' => 'ZONING', 'label' => 'Zoning Clearance'],
        ['code' => 'OCCUPANCY', 'label' => 'Occupancy Permit'],
        ['code' => 'CEC', 'label' => 'City Environmental Certificate'],
        ['code' => 'OTHER', 'label' => 'Other'],
    ],
    'amounts' => [
        'BUSINESS' => ['month' => 1000.10, 'year' => 4000.10],
        'SANITARY' => ['month' => 0.20, 'year' => 300.20],
        'FSIC' => ['month' => 0, 'year' => 500],
        'ZONING' => ['month' => 250, 'year' => 250],
        'OCCUPANCY' => ['month' => 0, 'year' => 0],
        'CEC' => ['month' => 0, 'year' => 0],
        'OTHER' => ['month' => 49.95, 'year' => 149.95],
    ],
    'payments' => ['month' => 3, 'year' => 9],
];

/*
 * Suspended permits, given out of order. Seven, so the list is cut to the
 * oldest five; one has no date (undated, never long, sorted last); two share a
 * date so the permit id breaks the tie.
 */
$suspendedPermits = [];
foreach ([
    [21, '2026-07-20', 10, false, null],
    [22, '2026-05-01', 90, true, ['permit' => 'Sanitary Permit', 'office_code' => 'CHO', 'office' => 'City Health Office']],
    [23, null, null, false, null],
    [24, '2026-06-01', 59, true, ['permit' => 'Fire Safety Inspection Certificate', 'office_code' => 'BFP', 'office' => 'Bureau of Fire Protection']],
    [25, '2026-06-01', 59, true, ['permit' => 'Zoning Clearance', 'office_code' => 'CPDO', 'office' => 'Planning/Zoning Office']],
    [26, '2026-07-01', 29, false, null],
    [27, '2026-03-15', 137, true, ['permit' => 'Sanitary Permit', 'office_code' => 'CHO', 'office' => 'City Health Office']],
] as [$id, $on, $days, $long, $waits]) {
    $suspendedPermits[] = [
        'permit_id' => $id,
        'permit_number' => "MP-2026-0000{$id}",
        'business_id' => $id + 100,
        'business' => "Fixture Business {$id}",
        'permit_code' => 'BUSINESS',
        'permit' => "Mayor's / Business Permit",
        'suspended_on' => $on,
        'days' => $days,
        'long' => $long,
        'waits_on' => $waits,
    ];
}

/*
 * Map points. Six decimal places, which is the precision jsonlite silently threw
 * away at its default of four until the serializer was told otherwise. One point
 * has NO barangay, so it must be excluded from the per-barangay aggregation but
 * still counted in `plotted` — and it must serialise as a JSON null in both
 * engines rather than the string "NA".
 */
$mapPoints = [
    ['business_id' => 1, 'business' => 'Aling Nena Sari-Sari', 'barangay' => 'Longos', 'latitude' => 14.662398, 'longitude' => 120.961482, 'permit_state' => 'active'],
    ['business_id' => 2, 'business' => 'Malabon Hardware', 'barangay' => 'Longos', 'latitude' => 14.661319, 'longitude' => 120.972104, 'permit_state' => 'lapsed'],
    ['business_id' => 3, 'business' => 'Tinajeros Bakeshop', 'barangay' => 'Tinajeros', 'latitude' => 14.647883, 'longitude' => 120.978329, 'permit_state' => 'active'],
    ['business_id' => 4, 'business' => 'Unmapped Barangay Co', 'barangay' => null, 'latitude' => 14.658513, 'longitude' => 120.952827, 'permit_state' => 'active'],
];

$dashboard = [
    'params' => ['months' => 12],
    'now' => '2026-07-30T00:00:00.000000Z',
    'today' => '2026-07-30',
    'window_start' => '2025-07-30',
    'ytd_start' => '2026-01-01',
    'month_start' => '2026-07-01',
    'top_n' => 5,
    'expiry_windows' => [30, 60, 90],
    'map_point_limit' => 1000,

    'kpis' => [
        'active_businesses' => 20,
        'applications_all_time' => 131,
        'applications_ytd' => 120,
        'applications_this_month' => 16,
    ],

    'volume' => [
        ['type' => 'new', 'label' => 'New', 'count' => 9],
        ['type' => 'renewal', 'label' => 'Renewals', 'count' => 6],
        // Zero amendments: the row must survive rather than vanish and break the
        // total.
        ['type' => 'amendment', 'label' => 'Amendments', 'count' => 1],
    ],

    /*
     * Approval rate = 8 / (8 + 4) = 66.666… -> 66.7. Returned (2) and pending
     * (4) may not reach the denominator: a returned filing is still owed a
     * decision, and dividing by the grand total of 18 would give 44.4%, which
     * is the mistake the spec warns about. No Cancelled row since 18 October
     * 2026 — applicants can no longer cancel.
     */
    'decisions' => [
        ['outcome' => 'approved', 'label' => 'Approved', 'count' => 8, 'decisioned' => true],
        ['outcome' => 'returned', 'label' => 'Returned for revision', 'count' => 2, 'decisioned' => false],
        ['outcome' => 'rejected', 'label' => 'Rejected', 'count' => 4, 'decisioned' => true],
        ['outcome' => 'pending', 'label' => 'Pending', 'count' => 4, 'decisioned' => false],
    ],

    'stage_observations' => $stageObservations,
    'stage_rules' => ['min_reviews' => 3, 'bottleneck_min_days_above' => 1.0, 'bottleneck_min_share_above' => 0.25],

    /*
     * The two ways a compliance indicator can end up without a rate:
     *   empty denominator     null rate, no reason to give
     *   numerator unknowable  null rate WITH a reason, which is the case that
     *                         must never render as 0%
     * The computable case (71 of 100) rode on the RA 11032 row, removed on
     * 18 October 2026; a computed rate is pinned by the seeded-register tests.
     */
    'compliance' => [
        [
            'indicator' => 'permit_validity',
            'label' => 'Business permit compliance',
            'numerator' => 0,
            'denominator' => 0,
            'numerator_label' => 'hold a valid permit for every type they have been issued',
            'denominator_label' => 'businesses ever issued a permit',
        ],
        [
            'indicator' => 'renewal',
            'label' => 'Renewal compliance',
            'numerator' => 0,
            'denominator' => 40,
            'numerator_label' => 'renewed before expiry',
            'denominator_label' => 'permits due for renewal',
            'unavailable_reason' => 'No renewal filing in this window records which permit it replaces, '
                .'so on-time renewals cannot be counted. This is a gap in the register, not a compliance finding.',
        ],
    ],

    'permit_type_columns' => [
        ['code' => 'BUSINESS', 'label' => "Mayor's / Business Permit"],
        ['code' => 'SANITARY', 'label' => 'Sanitary Permit'],
        ['code' => 'FSIC', 'label' => 'Fire Safety Inspection Certificate'],
        ['code' => 'ZONING', 'label' => 'Zoning Clearance'],
    ],
    'expiring_permits' => $expiringPermits,
    'barangays' => $barangays,
    'lines_of_business' => $linesOfBusiness,
    'organization_forms' => $organizationForms,
    'inspections' => $inspections,
    'fees' => $fees,
    'suspended_permits' => $suspendedPermits,
    'map' => [
        'mapped' => 4,
        'total_businesses' => 25,
        'points' => $mapPoints,
    ],
    /*
     * New and closed businesses. Three months on purpose: an ordinary month, a
     * month where closures outrun registrations (net must go negative, not be
     * floored at zero), and an empty month that must stay a row of zeros.
     */
    'business_movement' => [
        ['month' => '2026-05', 'registered' => 7, 'closed' => 2],
        ['month' => '2026-06', 'registered' => 1, 'closed' => 3],
        ['month' => '2026-07', 'registered' => 0, 'closed' => 0],
    ],
];

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents("{$dir}/dashboard.dataset.json", json_encode($dashboard, $flags)."\n");

echo "wrote dashboard.dataset.json\n";
