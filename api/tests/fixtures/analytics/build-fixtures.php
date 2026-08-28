<?php

/*
 * Builds the dashboard and growth/lifecycle fixture DATASETS.
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
 */

$dir = __DIR__;

/* ── dashboard ────────────────────────────────────────────────────────── */

/*
 * Tier observations. Three branches, and the numbers are chosen not just to be
 * over or under a limit but to land on values that expose rounding:
 *
 *   simple            mean of 3,4,5,5 = 4.25 working days against a 3-day limit.
 *                     BREACHING, and 4.25 rounds to 4.2 half-to-even and 4.3
 *                     under PHP's default rounding — the same trap the SPC
 *                     fixture caught. Overage is 1.25 -> 1.2.
 *   complex           mean of 6,6,7,7 = 6.5 against a 7-day limit. NOT
 *                     breaching, and 6.5 is another exact half.
 *   highly_technical  no observations at all: null means, and `breaching` must be
 *                     false rather than a comparison against null.
 */
/*
 * The recorded deadline is a flat ten working days for every tier, exactly as the
 * workflow sets it. That is deliberately NOT the statutory limit, and the fixture
 * carries the gap so the two yardsticks stay testably distinct: for the simple
 * tier only one filing of four is inside the 3-day statute while all four are
 * inside the 10-day internal deadline. Reporting the second as RA 11032
 * compliance is the bug this fixture exists to prevent coming back.
 */
$recordedDeadline = 10;

$tierObservations = [];
foreach ([3, 4, 5, 5] as $days) {
    $tierObservations[] = [
        'tier' => 'simple',
        'working_days' => $days,
        'calendar_days' => $days + 1.5,
        'within_statutory' => $days <= 3,
        'within_recorded_deadline' => $days <= $recordedDeadline,
        'recorded_deadline_working_days' => $recordedDeadline,
    ];
}
foreach ([6, 6, 7, 7] as $days) {
    $tierObservations[] = [
        'tier' => 'complex',
        'working_days' => $days,
        'calendar_days' => $days + 2.0,
        'within_statutory' => $days <= 7,
        'within_recorded_deadline' => $days <= $recordedDeadline,
        'recorded_deadline_working_days' => $recordedDeadline,
    ];
}

/*
 * Stage observations. BPLO and CHO differ, then three offices tie on BOTH mean
 * (0 days) and review count (1) — the exact shape that made PHP and R order the
 * table differently until a code tie-break was added to both. Codes are given out
 * of alphabetical order so a stable sort cannot pass by accident.
 */
$stageObservations = [];
foreach ([1.0, 2.0, 3.0] as $days) {
    $stageObservations[] = ['code' => 'BPLO', 'name' => 'Business Permits and Licensing Office', 'days' => $days];
}
foreach ([4.0, 5.0] as $days) {
    $stageObservations[] = ['code' => 'CHO', 'name' => 'City Health Office', 'days' => $days];
}
foreach ([['OBO', 'Office of the Building Official'], ['CENRO', 'City Environment Office'], ['BFP', 'Bureau of Fire Protection']] as [$code, $name]) {
    $stageObservations[] = ['code' => $code, 'name' => $name, 'days' => 0.0];
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
 * Officer activity. FOUR latencies, so the median is the midpoint of the two
 * central values — the branch a median implementation gets wrong. 2.0 and 3.5
 * average to 2.75, which rounds to 2.8 at one place. The mean of all four is
 * 12.125 -> 12.1.
 */
$officerActivity = [
    'response_hours' => [1.0, 2.0, 3.5, 42.0],
    'threads_awaiting_reply' => 2,
    'requests' => ['total' => 8, 'fulfilled' => 5],
    // Meetings scheduled AND partly attended, so the rate computes; the live
    // register has none scheduled and covers the null branch.
    'meetings' => ['scheduled' => 4, 'attended' => 3],
];

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
    'tiers' => [
        ['tier' => 'simple', 'label' => 'Simple', 'statutory_working_days' => 3],
        ['tier' => 'complex', 'label' => 'Complex', 'statutory_working_days' => 7],
        ['tier' => 'highly_technical', 'label' => 'Highly technical', 'statutory_working_days' => 20],
    ],
    'map_point_limit' => 1000,

    'kpis' => [
        'active_businesses' => 20,
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
     * Approval rate = 8 / (8 + 2 + 2) = 66.666… -> 66.7. Pending is 4 and
     * cancelled 0, and neither may reach the denominator: dividing by the grand
     * total of 16 would give 50%, which is the mistake the spec warns about.
     */
    'decisions' => [
        ['outcome' => 'approved', 'label' => 'Approved', 'count' => 8, 'decisioned' => true],
        ['outcome' => 'returned', 'label' => 'Returned for revision', 'count' => 2, 'decisioned' => true],
        ['outcome' => 'rejected', 'label' => 'Rejected', 'count' => 2, 'decisioned' => true],
        ['outcome' => 'pending', 'label' => 'Pending', 'count' => 4, 'decisioned' => false],
        ['outcome' => 'cancelled', 'label' => 'Cancelled', 'count' => 0, 'decisioned' => false],
    ],

    'tier_observations' => $tierObservations,
    'stage_observations' => $stageObservations,

    /*
     * The three ways a compliance indicator can end up:
     *   computable            71 of 100 -> 71.0
     *   empty denominator     null rate, no reason to give
     *   numerator unknowable  null rate WITH a reason, which is the case that
     *                         must never render as 0%
     */
    'compliance' => [
        [
            'indicator' => 'ra11032_processing',
            'label' => 'RA 11032 processing',
            'numerator' => 71,
            'denominator' => 100,
            'numerator_label' => 'decided within the legal deadline',
            'denominator_label' => 'decided filings',
        ],
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
        ['code' => 'SANITARY', 'label' => 'Sanitary Permit / Health Certificate'],
        ['code' => 'FSIC', 'label' => 'Fire Safety Inspection Certificate'],
        ['code' => 'ZONING', 'label' => 'Zoning / Locational Clearance'],
    ],
    'expiring_permits' => $expiringPermits,
    'barangays' => $barangays,
    'lines_of_business' => $linesOfBusiness,
    'organization_forms' => $organizationForms,
    'inspections' => $inspections,
    'officer_activity' => $officerActivity,
    'map' => [
        'mapped' => 4,
        'total_businesses' => 25,
        'points' => $mapPoints,
    ],
];

/* ── growth / lifecycle ──────────────────────────────────────────────── */

/*
 * Survival observations, built so the Kaplan-Meier product is checkable by hand
 * and so the censoring actually matters.
 *
 * Cohort 2023 (10 businesses): 2 lapse at cycle 1, 2 at cycle 2, and the rest are
 * censored along the way. At cycle 1 all 10 are at risk and 2 fail, so
 * S(1) = 1 - 2/10 = 0.8. Six reach cycle 2 and 2 fail, so
 * S(2) = 0.8 * (1 - 2/6) = 0.5333… -> 53.3.
 *
 * Cohort 2024 (4 businesses): 1 lapses at cycle 1, 3 censored there.
 * S(1) = 1 - 1/4 = 0.75.
 *
 * Cohort 2026 (3 businesses): every one still inside its FIRST permit, so time 0
 * and event 0. max_cycle is 0, no cycle is estimable, and the survival must be
 * NULL. This is the divide-by-zero guard the spec calls for, and the case where a
 * naive "renewed / total" ratio would print 0% and libel a cohort that has simply
 * not had a renewal yet.
 */
$cohorts = [];
$add = function (string $cohort, int $businessId, int $time, int $event) use (&$cohorts) {
    $cohorts[] = ['cohort' => $cohort, 'business_id' => $businessId, 'time' => $time, 'event' => $event];
};

$id = 1;
foreach ([[1, 1], [1, 1], [1, 0], [1, 0], [2, 1], [2, 1], [2, 0], [3, 0], [3, 0], [2, 0]] as [$time, $event]) {
    $add('2023', $id++, $time, $event);
}
foreach ([[1, 1], [1, 0], [1, 0], [1, 0]] as [$time, $event]) {
    $add('2024', $id++, $time, $event);
}
foreach ([[0, 0], [0, 0], [0, 0]] as [$time, $event]) {
    $add('2026', $id++, $time, $event);
}

$growth = [
    'params' => ['months' => 12],
    'now' => '2026-07-30T00:00:00.000000Z',
    'period_start' => '2025-07-30',
    'period_end' => '2026-07-30',
    'prior_period_start' => '2024-07-30',
    'top_n' => 6,
    'survival_methodology' => 'Of the businesses that reached each renewal, this is the share that '
        .'had renewed every earlier one with no gap in cover. Businesses still inside their current '
        .'permit are set aside rather than counted as failures. It describes what this group of '
        .'businesses did. It is not a forecast of what any business will do next.',
    'grace_days' => 30,

    // 40 against 32 gives +25.0% exactly.
    'registrations' => 40,
    'registrations_prior' => 32,
    'closures' => 7,

    // Totals 400, so a count of 5 is a 1.25% share -> 1.2 half-to-even.
    'status_counts' => ['active' => 300, 'expired' => 65, 'inactive' => 30, 'closed' => 5],

    /*
     * Barangays ranked by DELTA, not volume. Longos has the most registrations
     * but only +2, so it must not lead.
     *
     * Bulacan and Acacia are identical on BOTH sort keys — delta +6 and 10
     * registrations — which is what forces the name tie-break to decide, and
     * Bulacan is listed first so input order cannot supply the right answer by
     * accident. Ordering has to be total, or the table reshuffles between
     * refreshes and the two engines disagree.
     *
     * Flores has an empty prior period, so its growth_rate must be null while its
     * delta is still a number.
     */
    'barangays' => [
        ['barangay' => 'Longos', 'registrations' => 20, 'prior' => 18],
        ['barangay' => 'Bulacan', 'registrations' => 10, 'prior' => 4],
        ['barangay' => 'Acacia', 'registrations' => 10, 'prior' => 4],
        ['barangay' => 'Flores', 'registrations' => 4, 'prior' => 0],
        ['barangay' => 'Tonsuya', 'registrations' => 2, 'prior' => 9],
    ],

    'closure_months' => [
        ['month' => '2025-08', 'closures' => 0],
        ['month' => '2025-09', 'closures' => 2],
        ['month' => '2025-10', 'closures' => 1],
        ['month' => '2025-11', 'closures' => 4],
    ],

    /*
     * All three directions, and a count tie between the two 12s that has to break
     * on PSIC code.
     */
    'industries' => [
        ['industry' => 'Retail sale of hardware', 'psic_code' => '47521', 'count' => 30, 'registrations' => 10, 'prior' => 4],
        ['industry' => 'Food and beverage service', 'psic_code' => '56101', 'count' => 20, 'registrations' => 3, 'prior' => 9],
        ['industry' => 'Laundry services', 'psic_code' => '96200', 'count' => 12, 'registrations' => 5, 'prior' => 5],
        ['industry' => 'Bakery products', 'psic_code' => '10711', 'count' => 12, 'registrations' => 6, 'prior' => 1],
    ],

    'cohorts' => $cohorts,
];

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents("{$dir}/dashboard.dataset.json", json_encode($dashboard, $flags)."\n");
file_put_contents("{$dir}/growth-lifecycle.dataset.json", json_encode($growth, $flags)."\n");

echo "wrote dashboard.dataset.json and growth-lifecycle.dataset.json\n";
