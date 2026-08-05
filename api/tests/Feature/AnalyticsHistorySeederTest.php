<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
use App\Support\Spc;
use Database\Seeders\AnalyticsHistorySeeder;
use Illuminate\Support\Facades\DB;

/*
 * AnalyticsHistorySeeder writes demo history into a database real testers
 * share, so the contract that matters is not "does it produce nice charts" —
 * that is verified against the live endpoints — but "can it be told apart from
 * real work, and can it be taken back out without touching any of it".
 *
 * The generator is exercised over a four-month window rather than the full
 * thirty-six: the volume knobs are `protected` and read through `static::`
 * precisely so a subclass can do this. Same code path, a couple of seconds.
 */

/** Every table in the schema, and how many rows it holds right now. */
function tableCounts(): array
{
    $counts = [];
    foreach (DB::select("select name from sqlite_master where type='table' and name not like 'sqlite_%' order by name") as $row) {
        $counts[$row->name] = (int) DB::table($row->name)->count();
    }

    return $counts;
}

function shortHistorySeeder(): AnalyticsHistorySeeder
{
    return new class extends AnalyticsHistorySeeder
    {
        protected const MONTHS = 4;

        protected const VOLUME_START = 14;

        protected const VOLUME_END = 18;

        protected const OWNER_ACCOUNTS = 6;
    };
}

/**
 * The same generator at the same four months, but with the monthly volume dialled
 * up — for the one test that MEASURES the seeded data rather than checking its
 * shape.
 *
 * The injected slowdown lives in a fixed eight-ISO-week window, so lengthening
 * the history does nothing for it: however many months are seeded, the window
 * still holds only the filings that happen to fall in those eight weeks. At
 * `shortHistorySeeder`'s volume that is around twenty completed CHO reviews on
 * each side of the boundary, and the mean of twenty lognormal draws — some
 * carrying a two-to-five-day returned/resubmitted loop on top — is not a
 * measurement. It swung from x1.47 to x1.08 on a change that never touched the
 * slowdown at all: `permit_types.ZONING.requires_inspection` became true, which
 * removed one `reviewerFor()` call from the seeder, which shifted the single
 * `mt_srand` stream that every duration is drawn from. Nothing was diluted; the
 * old number had simply never been reliable enough to notice.
 *
 * Volume rather than months is what fixes it, because volume is what puts rows
 * inside the window: ~50 before and ~70 during, at which point the ratio lands
 * between x1.32 and x1.69 across anchor dates spread over a year, against a
 * ramp that predicts x1.35..x1.95. The control office stays between x0.89 and
 * x0.98. Both hold with a wide margin around the x1.25 the test asserts.
 */
function denseHistorySeeder(): AnalyticsHistorySeeder
{
    return new class extends AnalyticsHistorySeeder
    {
        protected const MONTHS = 4;

        protected const VOLUME_START = 36;

        protected const VOLUME_END = 44;

        protected const OWNER_ACCOUNTS = 12;
    };
}

/* ── separability ─────────────────────────────────────────────────────────── */

it('reports no seeded history on a register that only holds real work', function () {
    expect(AnalyticsHistorySeeder::isSeeded())->toBeFalse();
});

it('removes nothing at all when there is no seeded history to remove', function () {
    // The purge is the dangerous half: it deletes by `where … in`, and a bug in
    // how the seeded id set is built would take real filings with it.
    $before = tableCounts();

    expect(AnalyticsHistorySeeder::purge())->toBe([]);
    expect(tableCounts())->toBe($before);
});

it('is deliberately left out of DatabaseSeeder', function () {
    // Tests\TestCase::$seed runs DatabaseSeeder before every feature test, so
    // registering the history seeder there would put three years of invented
    // filings into all of them — and would break the analytics tests that
    // assert an empty prior period and a clean review history.
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))
        ->not->toContain('AnalyticsHistorySeeder');
});

/* ── what it writes ───────────────────────────────────────────────────────── */

it('writes tagged history that survives a round trip through the purge', function () {
    $before = tableCounts();

    shortHistorySeeder()->run();

    expect(AnalyticsHistorySeeder::isSeeded())->toBeTrue();

    $seededBusinesses = Business::withTrashed()
        ->where('registration_number', 'like', AnalyticsHistorySeeder::REGISTRATION_PREFIX.'%')
        ->count();
    expect($seededBusinesses)->toBeGreaterThan(0);

    // Tag 1 and tag 2 must agree: every seeded business is owned by a seeded
    // account, so the purge finds the same set from either direction.
    expect(Business::withTrashed()
        ->where('registration_number', 'like', AnalyticsHistorySeeder::REGISTRATION_PREFIX.'%')
        ->whereHas('owner', fn ($q) => $q->where('email', 'not like', '%@'.AnalyticsHistorySeeder::EMAIL_DOMAIN))
        ->count())->toBe(0);

    // Nothing new outside the tagged sets: every business added is a seeded one.
    expect(Business::withTrashed()->count() - $before['businesses'])->toBe($seededBusinesses);

    $afterSeed = tableCounts();
    expect($afterSeed['applications'])->toBeGreaterThan($before['applications']);
    expect($afterSeed['application_assignments'])->toBeGreaterThan($before['application_assignments']);
    expect($afterSeed['permits'])->toBeGreaterThan($before['permits']);

    // Running it again is a no-op rather than a second helping.
    shortHistorySeeder()->run();
    expect(tableCounts())->toBe($afterSeed);

    // And it all comes back out, leaving the register exactly as it was found.
    AnalyticsHistorySeeder::purge();
    expect(tableCounts())->toBe($before);
    expect(AnalyticsHistorySeeder::isSeeded())->toBeFalse();
});

it('writes timestamps that agree with each other', function () {
    shortHistorySeeder()->run();

    $businessIds = Business::withTrashed()
        ->where('registration_number', 'like', AnalyticsHistorySeeder::REGISTRATION_PREFIX.'%')
        ->pluck('id');
    $applications = Application::withTrashed()->whereIn('business_id', $businessIds)->get();

    expect($applications)->not->toBeEmpty();

    foreach ($applications as $app) {
        // Filed after the business was registered, never before it existed.
        expect($app->submitted_at)->not->toBeNull();
        expect($app->submitted_at->greaterThanOrEqualTo($app->business->created_at))->toBeTrue();
        // RA 11032 deadline is always ahead of the filing it applies to.
        expect($app->deadline_at->greaterThan($app->submitted_at))->toBeTrue();

        if ($app->decided_at !== null) {
            expect($app->decided_at->greaterThanOrEqualTo($app->submitted_at))->toBeTrue();
        }

        // A decided application has a terminal transition in its history, and
        // an undecided one does not — the two are never out of step.
        $terminal = $app->statusHistory()->whereIn('to_status', ['approved', 'rejected'])->exists();
        expect($terminal)->toBe($app->decided_at !== null);
    }

    foreach (ApplicationAssignment::whereIn('application_id', $applications->pluck('id'))->get() as $assignment) {
        expect($assignment->assigned_at)->not->toBeNull();
        if ($assignment->completed_at !== null) {
            expect($assignment->completed_at->greaterThan($assignment->assigned_at))->toBeTrue();
        }
    }

    foreach (Permit::whereIn('business_id', $businessIds)->get() as $permit) {
        expect($permit->valid_until > $permit->valid_from)->toBeTrue();
        // A permit whose validity has run out does not still read as active.
        if ($permit->valid_until < now()->toDateString()) {
            expect($permit->status->value)->not->toBe('active');
        }
    }

    AnalyticsHistorySeeder::purge();
});

/* ── the injected signal ──────────────────────────────────────────────────── */

it('actually slows the one office it says it slows', function () {
    // Dense rather than short: see denseHistorySeeder() for why this one test
    // needs the anomaly window populated instead of the history lengthened.
    denseHistorySeeder()->run();

    // The anomaly window is the eight ISO weeks before the current one.
    $anomalyStart = now()->startOfWeek()->subWeeks(8);

    $seededApplicationIds = Application::withTrashed()
        ->whereIn('business_id', Business::withTrashed()
            ->where('registration_number', 'like', AnalyticsHistorySeeder::REGISTRATION_PREFIX.'%')
            ->select('id'))
        ->pluck('id');

    $meanTurnaround = function (string $code, bool $inAnomalyWindow, int $minimumRows = 30) use ($seededApplicationIds, $anomalyStart): float {
        $rows = ApplicationAssignment::query()
            ->whereIn('application_id', $seededApplicationIds)
            ->where('department_id', Department::where('code', $code)->value('id'))
            ->whereNotNull('completed_at')
            ->where('assigned_at', $inAnomalyWindow ? '>=' : '<', $anomalyStart)
            ->get(['assigned_at', 'completed_at']);

        /*
         * Not merely "not empty". A mean over a handful of rows is a coin flip,
         * and this test has already been fooled once by exactly that — it read
         * x1.08 on a run whose slowdown was completely intact. Thirty is the
         * floor at which the observed ratios stopped straying near the
         * threshold; falling under it means the fixture stopped populating the
         * window, and the right answer is more volume, never a softer bound.
         */
        expect($rows->count())->toBeGreaterThanOrEqual($minimumRows);

        return $rows->avg(fn ($r) => $r->assigned_at->diffInSeconds($r->completed_at) / 86400);
    };

    // CHO is the office generate.R slows, so CHO is the office slowed here.
    $choBefore = $meanTurnaround('CHO', false);
    $choDuring = $meanTurnaround('CHO', true);
    expect($choDuring)->toBeGreaterThan($choBefore * 1.25);

    /*
     * And it is the slow office among SEVEN, not among four.
     *
     * The seeder used to route filings to BPLO, CHO, BFP and CPDO only, which
     * is why OBO, CENRO and the Market Office had three or four completed
     * reviews each on the live register and Permit Processing Time Monitoring
     * could chart four of the seven offices. They are seeded properly now — so
     * the claim "CHO is the office that slows" has three more offices it could
     * be wrong about, and this checks it against all of them rather than
     * against BPLO alone.
     *
     * Asserted as an ORDERING rather than a threshold per office. The three new
     * offices are deliberately the minor ones, so their windows hold fewer
     * reviews and their ratios are noisier; a fixed bound on each would be the
     * same coin flip this test was already fooled by once. "CHO moved further
     * than any other office" is the claim the seeder actually makes, it is
     * robust to that noise, and it is what the control chart has to show. The
     * margin is wide: CHO reads x1.37-x1.63 across anchor dates spread over a
     * year, and the next highest office never clears x1.17.
     */
    $ratios = [];
    foreach (['BPLO', 'BFP', 'CPDO', 'OBO', 'CENRO', 'CMO-MARKET'] as $code) {
        // A lower floor than CHO's: these offices are seeded as the quiet ones
        // on purpose, and requiring BPLO's volume of them would be requiring the
        // fixture to flatten exactly the difference it exists to show.
        $ratios[$code] = $meanTurnaround($code, true, 15) / $meanTurnaround($code, false, 15);
    }

    $choRatio = $choDuring / $choBefore;
    foreach ($ratios as $code => $ratio) {
        expect($choRatio)->toBeGreaterThan(
            $ratio,
            "{$code} moved as far as CHO (x{$ratio} against CHO's x{$choRatio}) — the injected "
                .'slowdown is no longer the only thing moving, or it is no longer on CHO.'
        );
    }

    // BPLO is the control: same window, no injected slowdown, so its mean must
    // not move anything like as far. Without this the test would pass on a bug
    // that slowed every office.
    $bploBefore = $meanTurnaround('BPLO', false);
    $bploDuring = $meanTurnaround('BPLO', true);
    expect($bploDuring)->toBeLessThan($bploBefore * 1.25);

    AnalyticsHistorySeeder::purge();
});

it('spreads history across the barangays and offices the register actually has', function () {
    shortHistorySeeder()->run();

    $businessIds = Business::withTrashed()
        ->where('registration_number', 'like', AnalyticsHistorySeeder::REGISTRATION_PREFIX.'%')
        ->pluck('id');

    $barangays = DB::table('business_addresses')
        ->whereIn('business_id', $businessIds)
        ->distinct()->count('barangay_id');
    expect($barangays)->toBeGreaterThan(8);

    /*
     * All seven offices, not the three the manuscript names.
     *
     * This asserted `toContain('BPLO', 'CHO', 'BFP')` and passed for a long time
     * while OBO, CENRO and the Market Office were getting three or four routed
     * filings each in total — the seeder loaded four departments and four permit
     * types, so nothing could ever be routed to the other three. Permit
     * Processing Time Monitoring needs `Spc::MIN_COMPLETIONS_PER_WEEK` finished
     * reviews in a week before it can average it, so those offices produced no
     * chartable week at all and the screen showed four of seven.
     *
     * `toBe` on the exact sorted list rather than `toContain`, so that dropping
     * an office fails here instead of silently reappearing as a footnote on the
     * chart.
     */
    $offices = ApplicationAssignment::query()
        ->whereIn('application_id', Application::withTrashed()->whereIn('business_id', $businessIds)->select('id'))
        ->distinct()->pluck('department_id')
        ->map(fn ($id) => Department::find($id)->code)
        ->sort()->values()->all();
    expect($offices)->toBe(['BFP', 'BPLO', 'CENRO', 'CHO', 'CMO-MARKET', 'CPDO', 'OBO']);

    AnalyticsHistorySeeder::purge();
});

/**
 * Every office can be charted, and the quiet ones are still visibly the quiet
 * ones.
 *
 * The client asked for the three empty offices to be filled ("fill it asw"),
 * and the failure mode of doing that carelessly is a register where all seven
 * offices carry identical volume — which charts, and lies. So both halves are
 * pinned: each office clears the minimum in enough weeks to draw a control
 * chart, AND the three busy offices are still meaningfully busier than the four
 * minor ones.
 *
 * Measured the way Spc measures it: bucketed by `completed_at` into ISO weeks,
 * counting only weeks that reach MIN_COMPLETIONS_PER_WEEK.
 */
it('gives every office enough completed reviews per week to be charted', function () {
    denseHistorySeeder()->run();

    $applicationIds = Application::withTrashed()
        ->whereIn('business_id', Business::withTrashed()
            ->where('registration_number', 'like', AnalyticsHistorySeeder::REGISTRATION_PREFIX.'%')
            ->select('id'))
        ->pluck('id');

    $chartableWeeks = function (string $code) use ($applicationIds): array {
        $rows = ApplicationAssignment::query()
            ->whereIn('application_id', $applicationIds)
            ->where('department_id', Department::where('code', $code)->value('id'))
            ->whereNotNull('completed_at')
            ->get(['assigned_at', 'completed_at']);

        $weeks = [];
        foreach ($rows as $row) {
            $weeks[$row->completed_at->startOfWeek()->toDateString()][] = 1;
        }

        return [
            'completed' => $rows->count(),
            'chartable' => count(array_filter($weeks, fn ($v) => count($v) >= Spc::MIN_COMPLETIONS_PER_WEEK)),
        ];
    };

    $busy = ['BPLO', 'CHO', 'BFP'];
    $minor = ['CPDO', 'OBO', 'CENRO', 'CMO-MARKET'];

    $stats = [];
    foreach ([...$busy, ...$minor] as $code) {
        $stats[$code] = $chartableWeeks($code);

        // Two chartable weeks is the arithmetic floor for a control chart: the
        // moving-range sigma needs a difference between consecutive points, so
        // one week gives limits of zero width and Spc reports the office as thin
        // rather than drawing it.
        expect($stats[$code]['chartable'])->toBeGreaterThanOrEqual(
            2,
            "{$code} has {$stats[$code]['chartable']} chartable weeks from {$stats[$code]['completed']} "
                .'completed reviews — it would fall back into the payload’s `thin` list.'
        );
    }

    /*
     * The shape, not just the presence. An occupancy permit, an environmental
     * certificate, a locational clearance and a market clearance are genuinely
     * not on every filing the way the health and fire certificates are, so the
     * busiest of the four minor offices must still sit below the quietest of
     * the three busy ones. If this ever fails, the attach rates have been
     * levelled up until the chart stopped telling the truth about the register.
     */
    $quietestBusy = min(array_map(fn ($c) => $stats[$c]['completed'], $busy));
    $busiestMinor = max(array_map(fn ($c) => $stats[$c]['completed'], $minor));

    expect($busiestMinor)->toBeLessThan(
        $quietestBusy,
        "The minor offices have caught the busy ones ({$busiestMinor} vs {$quietestBusy}); "
            .'the seeded volumes no longer distinguish them.'
    );

    AnalyticsHistorySeeder::purge();
});
