<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
use App\Support\Spc;
use Database\Seeders\AnalyticsHistorySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

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

/**
 * Every table in the schema, and how many rows it holds right now.
 *
 * Asked of the schema builder rather than of `sqlite_master`, so the purge is
 * proven on PostgreSQL — where it would actually run against a real register
 * — as well as on SQLite.
 */
function tableCounts(): array
{
    $counts = [];
    foreach (collect(Schema::getTableListing(schemaQualified: false))->sort() as $table) {
        $counts[$table] = (int) DB::table($table)->count();
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
 * inside the window: around fifty completed CHO reviews on each side.
 *
 * ── Why the slowdown test pins the clock ─────────────────────────────────
 *
 * Volume makes the ratio a measurement, but it is still a measurement of one
 * history, and the seeder builds that history from `Carbon::now()`: the filing
 * calendar, the anomaly window, the review cutoff, and through them every
 * `mt_rand` draw after. On the real clock the test measured a different history
 * every day it ran. It failed on 3 November 2026 at x1.24 against the x1.25 it
 * asserts, with nothing in the seeder changed.
 *
 * Run at 25 anchors from January 2026 to March 2027, it failed at 11. Reading
 * the seeder's own window (see the test) clears six of them. The other five
 * stay: CHO under x1.25 twice, CPDO edging past CHO twice, and too few CENRO
 * rows once. CHO ranged from x1.18 to x1.69. This comment used to promise
 * x1.32 to x1.69 on any anchor; the seeder as it stands does not do that.
 *
 * So the day is fixed, on one near the median of those 25 (CHO x1.41), not on
 * the best of them. A change to the seeder can still move the result. When it
 * does, look at what moved; do not pick another day.
 *
 * BPLO is not the quiet control it was meant to be. It reads under x0.5 at
 * every anchor, because its long rows end when the filing is decided (all 53
 * over ten days did, in the 3 November history): the row times the whole
 * filing, not BPLO reading the form. The ordering against the five other
 * offices is what rules out a slowdown on every office.
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
    // Dense rather than short, and on a fixed day: see denseHistorySeeder() for
    // why this one test needs the anomaly window populated, and why its anchor
    // must not be the day the suite happens to run.
    $anchor = Carbon::parse('2026-09-09 10:00:00');
    $this->travelTo($anchor);
    denseHistorySeeder()->run();

    /*
     * The anomaly window as the seeder draws it: the current ISO week and the
     * seven before it, so it opens seven Mondays back (`ANOMALY_WEEKS - 1`).
     * This read eight Mondays back, which put a week of CHO reviews the seeder
     * never slowed on the slowed side and pulled CHO's ratio down.
     *
     * From `$anchor`, not `now()`: run() hands back the real clock as it ends.
     */
    $anomalyStart = $anchor->copy()->startOfWeek()->subWeeks(7);

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
     * robust to that noise, and it is what the control chart has to show. At
     * the pinned anchor CHO reads x1.41 and the next office, CENRO, x1.14. On
     * the real clock a minor office did outrun CHO at two anchors of 25; see
     * denseHistorySeeder().
     */
    $ratios = [];
    foreach (['BPLO', 'BFP', 'CPDO', 'OBO', 'CENRO'] as $code) {
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
     * All six offices, not the three the manuscript names.
     *
     * This asserted `toContain('BPLO', 'CHO', 'BFP')` and passed for a long time
     * while OBO and CENRO were getting three or four routed filings each in
     * total — the seeder loaded four departments and four permit types, so
     * nothing could ever be routed to the rest. Permit Processing Time
     * Monitoring needs `Spc::MIN_COMPLETIONS_PER_WEEK` finished reviews in a
     * week before it can average it, so those offices produced no chartable week
     * at all and the screen showed four offices of the several that exist.
     *
     * Six rather than the seven this once read: the City Market Office was
     * removed with Market Clearance on 6 September 2026, leaving BPLO and the
     * five inspecting offices.
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
    expect($offices)->toBe(['BFP', 'BPLO', 'CENRO', 'CHO', 'CPDO', 'OBO']);

    AnalyticsHistorySeeder::purge();
});

/**
 * Every office can be charted, and the quiet ones are still visibly the quiet
 * ones.
 *
 * The client asked for the empty offices to be filled ("fill it asw"), and the
 * failure mode of doing that carelessly is a register where all six offices
 * carry identical volume — which charts, and lies. So both halves are pinned:
 * each office clears the minimum in enough weeks to draw a control chart, AND
 * the three busy offices are still meaningfully busier than the three minor
 * ones.
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
    $minor = ['CPDO', 'OBO', 'CENRO'];

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
     * certificate and a locational clearance are genuinely not asked for as
     * often as the health and fire certificates, so the busiest of the three
     * minor offices must still sit below the quietest of the three busy ones. If
     * this ever fails, the attach rates have been levelled up until the chart
     * stopped telling the truth about the register.
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

it('leaves no text or e-mail on the queue for its invented owners', function () {
    // A database queue, as on a server: anything queued here a worker would
    // later send for real, to random 09 numbers.
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();

    shortHistorySeeder()->run();

    expect(DB::table('jobs')->count())->toBe(0);
});
