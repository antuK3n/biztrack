<?php

use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Models\Application;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\PermitType;
use App\Support\DashboardAnalytics;
use Carbon\CarbonImmutable;

/*
 * Two client reports on the Analytics Dashboard, pinned so they cannot come
 * back.
 *
 * 1. "The offices listed in the Inspections are missing; should be all 6 (no
 *    BPLO)." The panel showed Sanitary, Fire Safety and Zoning only. The cause
 *    was a hard-coded three-office list in DashboardAnalytics that silently
 *    dropped every inspection row belonging to anyone else — the register
 *    already held hundreds of OBO, CENRO and Market inspections. The tests below
 *    assert the membership rule (who inspects is read from the register) rather
 *    than the six codes, because the six codes are the thing that changed.
 *
 * 2. "Do not put YTD only; it should be the full term." The yearly KPI counted
 *    from 1 January. It now counts the whole register.
 */

/** The offices the register says inspect: whoever issues an inspected permit type. */
function inspectingOfficeCodes(): array
{
    return PermitType::where('requires_inspection', true)
        ->get()
        ->map(fn (PermitType $type) => Department::findOrFail($type->issuing_department_id)->code)
        ->unique()
        ->sort()
        ->values()
        ->all();
}

/** The `type` of every row on the dashboard's inspections panel. */
function inspectionPanelCodes(): array
{
    $rows = DashboardAnalytics::build()['inspections']['rows'];

    return collect($rows)->pluck('type')->sort()->values()->all();
}

it('lists every office that inspects, and never BPLO', function () {
    $offices = inspectingOfficeCodes();

    // The client's "all 6 (no BPLO)": six supporting clearances are inspected,
    // the Mayor's Permit is not. If someone flips a requires_inspection flag,
    // this is the assertion that tells them the panel followed.
    expect($offices)->toHaveCount(6);
    expect($offices)->not->toContain('BPLO');

    expect(inspectionPanelCodes())->toBe($offices);
});

it('shows an office with no visits yet as an honest zero rather than omitting it', function () {
    /*
     * The failure this replaces was indistinguishable on screen from "this
     * office does not inspect", which is now false for all six. So the panel has
     * to keep the row even when there is nothing in it.
     *
     * CENRO is emptied rather than picked because it is empty: whichever office
     * we blank, the row must survive.
     */
    $cenro = Department::where('code', 'CENRO')->firstOrFail();
    Inspection::where('department_id', $cenro->id)->forceDelete();

    $rows = collect(DashboardAnalytics::build()['inspections']['rows'])
        ->keyBy('type');

    expect($rows)->toHaveKey('CENRO');
    expect($rows['CENRO']['scheduled'])->toBe(0);
    expect($rows['CENRO']['completed'])->toBe(0);
    // Not 0%: nothing was inspected, so nobody failed an inspection.
    expect($rows['CENRO']['pass_rate'])->toBeNull();
});

it('counts an inspection by every office towards the combined total', function () {
    /*
     * The old code dropped unknown offices *after* opening its buckets, so the
     * combined pass rate was a rate over three offices while the sentence
     * beneath it read "overall". Adding one visit to each office must move the
     * combined total by exactly that many.
     */
    $before = DashboardAnalytics::build()['inspections']['combined'];
    $application = Application::whereNull('deleted_at')->firstOrFail();
    $offices = inspectingOfficeCodes();

    foreach ($offices as $code) {
        Inspection::create([
            'application_id' => $application->id,
            'department_id' => Department::where('code', $code)->firstOrFail()->id,
            'status' => InspectionStatus::Completed,
            'result' => InspectionResult::Passed,
            'scheduled_at' => CarbonImmutable::now()->subDay(),
            'conducted_at' => CarbonImmutable::now(),
        ]);
    }

    $after = DashboardAnalytics::build()['inspections']['combined'];

    expect($after['scheduled'])->toBe($before['scheduled'] + count($offices));
    expect($after['completed'])->toBe($before['completed'] + count($offices));
    expect($after['passed'])->toBe($before['passed'] + count($offices));
});

/**
 * Backdate a copy of a real filing to a date the old 1-January cutoff excluded.
 *
 * The seeded register happens to be entirely within the current year, so
 * asserting the KPI against a plain `count()` would pass just as well with the
 * cutoff back in place. Every assertion about the full term has to be made
 * against a register that actually has a full term in it.
 */
function backdateAFilingToLastYear(): CarbonImmutable
{
    $lastYear = CarbonImmutable::now()->startOfYear()->subMonths(2);

    $application = Application::whereNull('deleted_at')->firstOrFail()->replicate();
    $application->tracking_id = 'FULLTERM-'.uniqid();
    $application->created_at = $lastYear;
    $application->updated_at = $lastYear;
    $application->save();

    return $lastYear;
}

it('counts the whole register on the yearly KPI, not the year to date', function () {
    /*
     * The key is still `applications_ytd` and the figure under it is the whole
     * register, which is a mismatch this test exists to pin rather than to
     * excuse. It could not be renamed while R echoed the name verbatim and the
     * parity check was byte-strict on data keys; that is no longer true, so the
     * rename is now this codebase's to make. It has not been made because the
     * name travels — stored snapshots, the golden baseline, the PDF and the
     * client all carry it — and renaming it is a coordinated change, not a
     * tidy-up. See DashboardAnalytics::kpiFacts().
     */
    backdateAFilingToLastYear();

    $total = Application::whereNull('deleted_at')
        ->where('created_at', '<=', CarbonImmutable::now())
        ->count();
    $thisYear = Application::whereNull('deleted_at')
        ->where('created_at', '>=', CarbonImmutable::now()->startOfYear())
        ->count();

    // Guards the guard: if these were ever equal the assertion below would hold
    // with the cutoff restored, and this test would be worth nothing.
    expect($total)->toBeGreaterThan($thisYear);

    expect(DashboardAnalytics::build()['kpis']['applications_ytd'])->toBe($total);
});

it('keeps the full-term and this-month KPIs distinguishable', function () {
    backdateAFilingToLastYear();

    $kpis = DashboardAnalytics::build()['kpis'];

    // Not one figure printed twice under two headings: "This Month" still means
    // this month, and it is a strict subset of the term.
    expect($kpis['applications_this_month'])->toBeLessThan($kpis['applications_ytd']);
});
