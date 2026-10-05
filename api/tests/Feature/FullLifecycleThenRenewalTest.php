<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\UnbilledPermitFee;
use App\Models\User;

// Visits are booked on a weekday in office hours (manage item 4).
beforeEach(fn () => duringOfficeHours());

/*
 * The whole thing, end to end, through the endpoints a browser calls.
 *
 * Apply for every permit, walk it to BPLO's final approval, then renew. Not a
 * unit test of any one rule — the point is the JOINS: every gate that only
 * fires when a real filing arrives at it in a real state, and every assumption
 * one stage makes about what an earlier stage left behind.
 *
 * Driven through HTTP and not the service, deliberately. A workflow defect that
 * only a controller can produce — a permission gate, a 422 on a payload the
 * browser sends, an endpoint that needs a state nothing reaches — is invisible
 * to a test that calls WorkflowService directly, and those are the ones that
 * reach a tester.
 *
 * ── Every actor re-authenticates before it acts ───────────────────────────
 *
 * `authAs()` returns an EMPTY header array and switches the Sanctum guard, so
 * `withHeaders($owner)` restores nothing. An applicant action written after an
 * officer's therefore runs AS THAT OFFICER, and the first draft of this file
 * did exactly that: the second clearance in the loop came back 403 because the
 * fire officer was pressing Apply on the applicant's behalf. Every helper below
 * names the account it acts as, on the line it acts.
 */

/** Which seeded officer signs for which clearance. */
const OFFICER_FOR = [
    'ZONING' => 'zoning@biztrack.local',
    'SANITARY' => 'sanitary@biztrack.local',
    'CEC' => 'cenro@biztrack.local',
    'FSIC' => 'fire@biztrack.local',
    'OCCUPANCY' => 'obo@biztrack.local',
];

/** Enough answers to satisfy each office sheet's required fields. */
const SHEET_ANSWERS = [
    'ZONING' => [
        'zoning_home_address' => '27 Rizal Avenue Extension, Brgy. Concepcion, Malabon City',
        'zoning_project_description' => 'Two-storey hardware and construction supply store with a stockroom at the rear.',
    ],
    'SANITARY' => [],
    'CEC' => [
        'owner_address' => '27 Rizal Avenue Extension, Brgy. Concepcion, Malabon City',
        'owner_birthday' => '1979-03-14',
        'certified' => 'yes',
    ],
    'FSIC' => ['authorized_representative' => 'Ma. Teresa R. Bautista'],
    'OCCUPANCY' => [],
];

/** A registered business, as the counter would have it on file. */
function registerBusiness(string $name, string $registrationNumber): int
{
    authAs('owner@biztrack.local');

    return test()->postJson('/api/v1/businesses', [
        'name' => $name,
        'trade_name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '246-813-579-000',
        'president_officer_name' => 'Ma. Teresa R. Bautista',
        'owner' => [
            'surname' => 'Bautista',
            'given_name' => 'Ma. Teresa',
            'middle_name' => 'Reyes',
            'gender' => 'F',
        ],
        'address' => [
            'line1' => '127 F. Sevilla Boulevard',
            'barangay_id' => Barangay::where('name', 'Tañong')->value('id'),
            'telephone' => '(02) 8281-4712',
            'mobile_number' => '0917-842-6531',
            'email' => 'materesa.bautista@example.ph',
        ],
        'lines' => [[
            'psic_code_id' => PsicCode::where('code', '47521')->value('id'),
            'line_of_business' => 'Retail sale of hardware and building materials',
            'products_services' => 'Cement, steel bars, plywood, paint, plumbing fixtures',
            'capitalization' => 850000,
        ]],
    ])->assertCreated()->json('data.id');
}

/** The fee inputs a hardware store of this size would declare. */
function feeProfile(int $grossReceipts): array
{
    return [
        'floor_area_sqm' => 145,
        'storeys' => 2,
        'employees' => 7,
        'lines' => [['category' => 'retailer', 'gross_receipts' => $grossReceipts]],
    ];
}

/** BPLO reads the form, categorises it, and approves it for payment. */
function bploAccepts(int $appId, string $remarks): void
{
    authAs('bplo@biztrack.local');
    $assignment = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();

    test()->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'simple'])
        ->assertOk();
    /*
     * Ticking all five other permits, through the endpoint BPLO's screen
     * uses: since 5 October 2026 a new filing is submitted with the business
     * permit alone and BPLO picks the rest here (client: "BPLO decides").
     */
    test()->postJson("/api/v1/assignments/{$assignment->id}/approve", [
        'remarks' => $remarks,
        'permit_type_ids' => PermitType::whereIn('code', PermitType::REQUIRED_CLEARANCE_CODES)->pluck('id')->all(),
    ])->assertOk();
}

/**
 * One clearance, all the way: the applicant applies and fills the sheet, then
 * the office accepts the paperwork, books a visit and passes it.
 */
/**
 * Everything the APPLICANT does for one clearance: apply, answer, attach, submit.
 *
 * Split out of `clearanceEndToEnd` on 4 October 2026. A clearance-only renewal
 * never does any of it: `WorkflowService::submit` hands its office sheet in as
 * part of the submission (`handCarriedClearancesToTheirOffices`), because that
 * sheet is a step of the renewal wizard rather than a later press of Apply.
 * Calling `/apply` on such a filing is refused — the clearance is already
 * started — which is how the renewal case below found this.
 */
function clearanceApplicantHalf(int $appId, string $code): void
{
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")->assertOk();
    /*
     * The answers go in FIRST, on their own save, and the checklist is then
     * completed against the sheet as answered.
     *
     * Order matters here and did not before 30 September 2026. Some rows
     * only EXIST once a question is answered — FSIC asks for the SPA
     * because `authorized_representative` is filled in, and that is one of
     * the answers in this very call — so attaching against the blank sheet
     * misses the row the same request goes on to create, and the submit
     * refuses a document nothing had asked for yet.
     *
     * It is also what the applicant's own screen does: the answers autosave
     * as they are typed and Submit is a separate press.
     */
    test()->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
        'form_data' => SHEET_ANSWERS[$code] ?? [],
    ])->assertOk();

    // Then the documents that sheet asks for. See satisfyChecklist() in Pest.php.
    satisfyChecklist(
        Application::findOrFail($appId),
        PermitType::where('code', $code)->firstOrFail(),
    );

    test()->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
        'form_data' => SHEET_ANSWERS[$code] ?? [],
        'submit' => true,
    ])->assertOk();

}

/** Everything the OFFICE does for one clearance: approve, schedule, inspect. */
function clearanceOfficeHalf(int $appId, string $code): void
{
    authAs(OFFICER_FOR[$code]);
    $assignment = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', PermitType::where('code', $code)->value('issuing_department_id'))
        ->firstOrFail();

    test()->postJson("/api/v1/assignments/{$assignment->id}/approve", [
        'remarks' => 'Requirements complete. Endorsed for inspection.',
    ])->assertOk();

    $inspectionId = test()->postJson("/api/v1/applications/{$appId}/permits/{$code}/inspection", [
        'scheduled_at' => now()->toDateTimeString(), // today: no result before the booked day
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/inspections/{$inspectionId}/conduct", [
        'result' => 'passed',
        'findings' => 'Premises inspected and found compliant.',
    ])->assertOk();
}

/** Both halves, which is the whole of a NEW filing's clearance stage. */
function clearanceEndToEnd(int $appId, string $code): void
{
    clearanceApplicantHalf($appId, $code);
    clearanceOfficeHalf($appId, $code);
}

/** BPLO's last act: every office is done, so issue. */
function permitCodesOn(int $appId): array
{
    return Application::findOrFail($appId)
        ->permits()->with('permitType')->get()
        ->pluck('permitType.code')->sort()->values()->all();
}

it('walks a new application from filing to every permit issued', function () {
    $businessId = registerBusiness('Bautista Hardware & Construction Supply', 'DTI-2026-0451233');

    // ── 1. File for the business permit; BPLO's approval attaches the rest ─
    authAs('owner@biztrack.local');
    $appId = $this->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'data_privacy_consent' => true,
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'fee_profile' => feeProfile(1_850_000),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    expect(Application::find($appId)->permitTypes()->pluck('code')->sort()->values()->all())
        ->toBe(['BUSINESS']);

    // ── 2. BPLO accepts the form; the Tax Order of Payment is raised ─────
    bploAccepts($appId, 'Form complete. Assessed as a simple transaction.');
    expect(Application::find($appId)->status->value)->toBe('pending_payment');
    expect(Application::find($appId)->permitTypes()->pluck('code')->sort()->values()->all())
        ->toBe(['BUSINESS', 'CEC', 'FSIC', 'OCCUPANCY', 'SANITARY', 'ZONING']);

    // ── 3. Payment opens the clearance stage ─────────────────────────────
    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();
    expect(Application::find($appId)->status->value)->toBe('approved');

    // ── 4. All five clearances, each with its own office ─────────────────
    foreach (array_keys(OFFICER_FOR) as $code) {
        clearanceEndToEnd($appId, $code);
    }
    /*
     * The fifth clearance closes the filing outright — there is no step 5.
     *
     * BPLO's final approval used to sit here. The client asked what it checked
     * when every clearance is applied for and approved inside BizTrack, and the
     * answer was nothing, so `refreshReadiness()` now issues the Mayor's Permit
     * as the last office finishes. The stage survives only on the renewal path,
     * which is the second half of this very test.
     */
    expect(Application::find($appId)->status->value)->toBe('approved');

    // ── 6. Six certificates, one per permit ──────────────────────────────
    expect(permitCodesOn($appId))
        ->toBe(['BUSINESS', 'CEC', 'FSIC', 'OCCUPANCY', 'SANITARY', 'ZONING']);

    foreach (Application::find($appId)->permits as $permit) {
        expect($permit->permit_number)->not->toBeEmpty()
            ->and($permit->valid_until)->not->toBeNull();
    }

    // And they reach the applicant through the endpoint their profile reads.
    authAs('owner@biztrack.local');
    $mine = $this->getJson('/api/v1/permits')->assertOk()->json('data');
    expect(collect($mine)->pluck('permit_type.code')->all())
        ->toContain('BUSINESS', 'CEC', 'ZONING', 'SANITARY', 'FSIC', 'OCCUPANCY');
})->group('lifecycle');

it('renews one of the six and issues only that', function () {
    /*
     * The second half of the client's request, and the case the old code
     * broke: a renewal that leaves the Mayor's Permit alone. The prior
     * permits are staggered on purpose — differing expiry dates are the
     * client's whole reason for renewing part of a holding.
     *
     * ── It renewed TWO until 3 October 2026 ────────────────────
     *
     * Sanitary and Zoning on one filing, which `App\Support\RenewalScope`
     * now refuses: the client ruled that each of the other permits is
     * renewed on its own application, because each is read by its own
     * office and the two share no step, no fee and no reviewer.
     *
     * This test is also why the 30-day early window was reverted on
     * 1 October — it refused that staggered pair — and the reason went with
     * the pair: those six clearances are six filings now whatever the window
     * is set to, so the window went back on the day the scope rule landed.
     * The two are one decision seen twice.
     *
     * Everything the test was written to prove survives at one permit: the
     * set is not expanded to six, BPLO is not involved, nothing is billed
     * now, and the fee waits for January.
     */
    $businessId = registerBusiness('Bautista Hardware & Construction Supply', 'DTI-2026-0451233');
    $ownerId = User::where('email', 'owner@biztrack.local')->value('id');

    $priorApp = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => $ownerId,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    /*
     * Staggered, with the one being renewed INSIDE its 30-day window and the
     * rest well outside it.
     *
     * The spread was `15 + ($i * 45)` by position, which put Sanitary 105
     * days out — fine while nothing bounded an early renewal, and refused at
     * submission once one did. `RenewalWindow` is enforced in
     * `ApplicationController`, not merely drawn in the picker.
     *
     * Named per code rather than derived from the loop index, so reordering
     * the list below cannot silently move which permit is the due one.
     */
    $expiresInDays = [
        'BUSINESS' => 200,
        'ZONING' => 150,
        /* The one this filing renews. */
        'SANITARY' => 15,
        'CEC' => 180,
        'FSIC' => 220,
        'OCCUPANCY' => 260,
    ];

    $prior = [];
    foreach (['BUSINESS', 'ZONING', 'SANITARY', 'CEC', 'FSIC', 'OCCUPANCY'] as $i => $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $prior[$code] = Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $businessId,
            'permit_type_id' => $type->id,
            'permit_number' => $type->permit_number_prefix.'-2025-'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays($expiresInDays[$code]),
            'status' => 'active',
        ]);
    }

    // ── 1. File a renewal for the one that is falling due ───────────────
    $renewCodes = ['SANITARY'];
    authAs('owner@biztrack.local');
    $appId = $this->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'renewal',
        'data_privacy_consent' => true,
        'permit_type_ids' => PermitType::whereIn('code', $renewCodes)->pluck('id')->all(),
        'prior_permit_id' => $prior['SANITARY']->id,
        'prior_permit_ids' => [$prior['SANITARY']->id],
        'fee_profile' => feeProfile(2_100_000),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    // NOT expanded to six.
    expect(Application::find($appId)->permitTypes()->pluck('code')->sort()->values()->all())
        ->toBe($renewCodes);

    /*
     * ── 2. NOT the path a new filing takes, since 17 September 2026 ──────────
     *
     * This walked the renewal through BPLO and a payment: `bploAccepts`, then
     * `/pay`, then a final BPLO approval at the end. None of that happens on a
     * renewal carrying no business permit any more.
     *
     *  - BPLO is never routed it (`submit` skips the routing), so `bploAccepts`
     *    failed looking up an assignment that does not exist — which is how
     *    this test caught the change.
     *  - it is never billed. The fee is collected on the next business permit
     *    renewal, so there is nothing to pay here and `/pay` has no bill.
     *  - it closes itself when the last permit is granted, so there is no
     *    final approval to give.
     *
     * The three properties this test was written for are unchanged and still
     * asserted: the permit set is not expanded, the stage offers what was
     * ticked rather than five, and exactly that many certificates come out.
     */
    expect(Application::find($appId)->defersPayment())->toBeTrue();

    /*
     * No BPLO queue item on a filing BPLO has no say in.
     *
     * Asserted against BPLO specifically rather than against a count of zero.
     * It was a flat `->count())->toBe(0)` until 4 October 2026, which said
     * "nobody is routed this" when what it meant was "BPLO is not" — and the
     * two stopped being the same thing when clearance-only renewals began
     * reaching the office that issues the permit
     * (`handCarriedClearancesToTheirOffices`). The client had reported the
     * opposite failure: a Sanitary renewal that reached no queue at all. So
     * the broad assertion was pinning the bug, and the narrow one is the
     * property this test was actually written for.
     */
    $bploId = Department::where('code', 'BPLO')->value('id');
    expect(
        ApplicationAssignment::where('application_id', $appId)->where('department_id', $bploId)->count()
    )->toBe(0);

    // And it did reach somebody: the office that issues what is being renewed.
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBeGreaterThan(0);

    // The stage is already open — there is no payment to wait for.
    authAs('owner@biztrack.local');
    $rows = $this->getJson("/api/v1/applications/{$appId}/clearances")->assertOk();
    expect($rows->json('meta.unlocked'))->toBeTrue();
    expect(collect($rows->json('data'))->pluck('permit_type.code')->sort()->values()->all())
        ->toBe($renewCodes);

    /*
     * Only the OFFICE half. The applicant's half — Apply, answer the sheet,
     * attach, submit — already happened inside `submit`, because on a
     * clearance-only renewal the office sheet is a step of the wizard rather
     * than a later press of Apply. Running `clearanceApplicantHalf` here
     * would be the applicant applying twice, and `/apply` refuses it.
     */
    foreach ($renewCodes as $code) {
        clearanceOfficeHalf($appId, $code);
    }

    /*
     * ── 3. It closed itself, and issued exactly the two ─────────────────────
     */
    expect(Application::find($appId)->status->value)->toBe('approved');
    expect(permitCodesOn($appId))->toBe($renewCodes);

    /*
     * And the money is waiting for January rather than lost. The permit is
     * issued unpaid and a receivable stands against the business — the whole
     * of the deferral the client asked for, seen from the HTTP side.
     */
    $deferred = UnbilledPermitFee::where('business_id', $businessId)->outstanding()->get();
    expect($deferred)->toHaveCount(1);
    expect($deferred->sum('amount'))->toBeGreaterThan(0);
})->group('lifecycle');
