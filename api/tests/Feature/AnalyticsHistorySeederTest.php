<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
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
    shortHistorySeeder()->run();

    // The anomaly window is the eight ISO weeks before the current one.
    $anomalyStart = now()->startOfWeek()->subWeeks(8);

    $seededApplicationIds = Application::withTrashed()
        ->whereIn('business_id', Business::withTrashed()
            ->where('registration_number', 'like', AnalyticsHistorySeeder::REGISTRATION_PREFIX.'%')
            ->select('id'))
        ->pluck('id');

    $meanTurnaround = function (string $code, bool $inAnomalyWindow) use ($seededApplicationIds, $anomalyStart): float {
        $rows = ApplicationAssignment::query()
            ->whereIn('application_id', $seededApplicationIds)
            ->where('department_id', Department::where('code', $code)->value('id'))
            ->whereNotNull('completed_at')
            ->where('assigned_at', $inAnomalyWindow ? '>=' : '<', $anomalyStart)
            ->get(['assigned_at', 'completed_at']);

        expect($rows)->not->toBeEmpty();

        return $rows->avg(fn ($r) => $r->assigned_at->diffInSeconds($r->completed_at) / 86400);
    };

    // CHO is the office generate.R slows, so CHO is the office slowed here.
    $choBefore = $meanTurnaround('CHO', false);
    $choDuring = $meanTurnaround('CHO', true);
    expect($choDuring)->toBeGreaterThan($choBefore * 1.25);

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

    $offices = ApplicationAssignment::query()
        ->whereIn('application_id', Application::withTrashed()->whereIn('business_id', $businessIds)->select('id'))
        ->distinct()->pluck('department_id')
        ->map(fn ($id) => Department::find($id)->code)
        ->sort()->values()->all();
    expect($offices)->toContain('BPLO', 'CHO', 'BFP');

    AnalyticsHistorySeeder::purge();
});
