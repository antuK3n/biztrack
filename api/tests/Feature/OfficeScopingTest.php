<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationOfficeForm;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\MessageAttachment;
use App\Models\Payment;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/*
 * Tester checklist item 56 — "no they cant see what theyre not included in."
 *
 * An office reads the filings that were routed to it and nothing else. BPLO
 * issues the mayor's permit and coordinates every other clearance, and the
 * super admin audits the system, so both keep the whole register.
 *
 * Every case asserts both directions, and asserts the refusal is a 403 (or an
 * absence from a list) rather than a 500 — a crash is not access control.
 */

/**
 * File and pay for an application carrying exactly these permit types, so
 * WorkflowService routes it to exactly those issuing departments.
 *
 * @param  list<string>  $permitCodes
 * @return array{id:int, business_id:int}
 */
function fileRoutedApplication(string $businessName, array $permitCodes): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $businessName,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Scoping Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', $permitCodes)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return ['id' => $appId, 'business_id' => $businessId];
}

/** The ids the caller can see in the applications list. */
function listedApplicationIds(string $email): array
{
    return collect(
        test()->withHeaders(authAs($email))->getJson('/api/v1/applications')->assertOk()->json('data')
    )->pluck('id')->all();
}

it('routes a filing only to the offices that own its permit types', function () {
    $app = fileRoutedApplication('Scoping Sanity Check', ['BUSINESS', 'SANITARY']);

    expect(
        Application::find($app['id'])->assignments()->with('department')->get()
            ->pluck('department.code')->sort()->values()->all()
    )->toBe(['BPLO', 'CHO']);
});

it('lists a filing for the office it was routed to', function () {
    $app = fileRoutedApplication('Scoping Included Cafe', ['BUSINESS', 'SANITARY']);

    expect(listedApplicationIds('sanitary@biztrack.local'))->toContain($app['id']);
});

it('keeps a filing out of the list of every office it was not routed to', function (string $email) {
    $app = fileRoutedApplication('Scoping Excluded Cafe', ['BUSINESS', 'SANITARY']);

    expect(listedApplicationIds($email))->not->toContain($app['id']);
})->with([
    'fire@biztrack.local',
    'obo@biztrack.local',
    'cenro@biztrack.local',
    'market@biztrack.local',
    'zoning@biztrack.local',
]);

it('lets the office it was routed to read the application and its timeline', function () {
    $app = fileRoutedApplication('Scoping Read Cafe', ['BUSINESS', 'SANITARY']);
    $sanitary = authAs('sanitary@biztrack.local');

    test()->withHeaders($sanitary)
        ->getJson("/api/v1/applications/{$app['id']}")
        ->assertOk()
        ->assertJsonPath('data.id', $app['id']);

    test()->withHeaders($sanitary)
        ->getJson("/api/v1/applications/{$app['id']}/timeline")
        ->assertOk();
});

it('refuses an outside office on every surface that carries the application', function () {
    $app = fileRoutedApplication('Scoping Refusal Cafe', ['BUSINESS', 'SANITARY']);
    $paymentId = Payment::where('application_id', $app['id'])->value('id');

    // BFP owns FSIC, which this filing never asked for.
    $fire = authAs('fire@biztrack.local');

    test()->withHeaders($fire)->getJson("/api/v1/applications/{$app['id']}")->assertForbidden();
    test()->withHeaders($fire)->getJson("/api/v1/applications/{$app['id']}/timeline")->assertForbidden();
    test()->withHeaders($fire)->getJson("/api/v1/applications/{$app['id']}/office-forms")->assertForbidden();
    test()->withHeaders($fire)->getJson("/api/v1/applications/{$app['id']}/messages")->assertForbidden();
    test()->withHeaders($fire)->postJson("/api/v1/applications/{$app['id']}/messages", [
        'body' => 'Let me in.',
    ])->assertForbidden();
    test()->withHeaders($fire)->getJson("/api/v1/businesses/{$app['business_id']}")->assertForbidden();
    test()->withHeaders($fire)->getJson("/api/v1/payments/{$paymentId}/receipt")->assertForbidden();
    test()->withHeaders($fire)->postJson("/api/v1/applications/{$app['id']}/requests", [
        'request_type' => 'document', 'title' => 'Send me the fire plan',
    ])->assertForbidden();
    test()->withHeaders($fire)->putJson("/api/v1/applications/{$app['id']}/office-forms/SANITARY", [
        'form_data' => ['date_issued' => '2026-01-05'],
    ])->assertForbidden();
});

it('lets the office it was routed to reach the same surfaces', function () {
    $app = fileRoutedApplication('Scoping Allowed Cafe', ['BUSINESS', 'SANITARY']);
    $paymentId = Payment::where('application_id', $app['id'])->value('id');
    $sanitary = authAs('sanitary@biztrack.local');

    test()->withHeaders($sanitary)->getJson("/api/v1/applications/{$app['id']}/office-forms")->assertOk();
    test()->withHeaders($sanitary)->getJson("/api/v1/applications/{$app['id']}/messages")->assertOk();
    test()->withHeaders($sanitary)->postJson("/api/v1/applications/{$app['id']}/messages", [
        'body' => 'Please confirm your water source.',
    ])->assertCreated();
    test()->withHeaders($sanitary)->getJson("/api/v1/businesses/{$app['business_id']}")->assertOk();
    test()->withHeaders($sanitary)->getJson("/api/v1/payments/{$paymentId}/receipt")->assertOk();
    test()->withHeaders($sanitary)->postJson("/api/v1/applications/{$app['id']}/requests", [
        'request_type' => 'document', 'title' => 'Send the water potability result',
    ])->assertCreated();
});

it('cuts both ways: the fire office reads its own filing and sanitary cannot', function () {
    $app = fileRoutedApplication('Scoping Fire Only Cafe', ['BUSINESS', 'FSIC']);

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/applications/{$app['id']}")
        ->assertOk();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$app['id']}")
        ->assertForbidden();

    expect(listedApplicationIds('fire@biztrack.local'))->toContain($app['id'])
        ->and(listedApplicationIds('sanitary@biztrack.local'))->not->toContain($app['id']);
});

it('keeps the whole register for BPLO and the super admin', function (string $email) {
    $app = fileRoutedApplication('Scoping Register Cafe', ['BUSINESS', 'SANITARY']);
    $everything = Application::pluck('id')->all();

    expect(listedApplicationIds($email))->toEqualCanonicalizing($everything);

    test()->withHeaders(authAs($email))
        ->getJson("/api/v1/applications/{$app['id']}")
        ->assertOk();
    test()->withHeaders(authAs($email))
        ->getJson("/api/v1/applications/{$app['id']}/timeline")
        ->assertOk();
})->with(['bplo@biztrack.local', 'admin@biztrack.local']);

it('shows an office only the message threads on filings it is part of', function () {
    $mine = fileRoutedApplication('Scoping Thread Mine', ['BUSINESS', 'SANITARY']);
    $theirs = fileRoutedApplication('Scoping Thread Theirs', ['BUSINESS', 'FSIC']);

    $owner = authAs('owner@biztrack.local');
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$mine['id']}/messages", ['body' => 'Hello CHO.'])->assertCreated();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$theirs['id']}/messages", ['body' => 'Hello BFP.'])->assertCreated();

    $threads = collect(
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson('/api/v1/message-threads')->assertOk()->json('data')
    )->pluck('application_id');

    expect($threads)->toContain($mine['id'])
        ->and($threads)->not->toContain($theirs['id']);
});

it('shows an office only the requirement requests on filings it is part of', function () {
    $mine = fileRoutedApplication('Scoping Request Mine', ['BUSINESS', 'SANITARY']);
    $theirs = fileRoutedApplication('Scoping Request Theirs', ['BUSINESS', 'FSIC']);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$theirs['id']}/requests", [
            'request_type' => 'document', 'title' => 'Fire safety plan',
        ])->assertCreated();
    $hidden = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/requests')->assertOk()->json('data');

    $visible = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$mine['id']}/requests", [
            'request_type' => 'document', 'title' => 'Water potability result',
        ])->assertCreated()->json('data.id');

    $seen = collect(
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson('/api/v1/requests')->assertOk()->json('data')
    )->pluck('id');

    expect($seen)->toContain($visible)
        ->and($seen)->not->toContain(collect($hidden)->firstWhere('title', 'Fire safety plan')['id']);
});

it('refuses an outside office closing another office’s request', function () {
    $app = fileRoutedApplication('Scoping Close Cafe', ['BUSINESS', 'SANITARY']);

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/requests", [
            'request_type' => 'document', 'title' => 'Water potability result',
        ])->assertCreated()->json('data.id');

    test()->withHeaders(authAs('market@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertForbidden();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
});

it('leaves the applicant’s own view untouched', function () {
    $app = fileRoutedApplication('Scoping Owner Cafe', ['BUSINESS', 'SANITARY']);
    $owner = authAs('owner@biztrack.local');

    expect(listedApplicationIds('owner@biztrack.local'))->toContain($app['id']);

    test()->withHeaders($owner)->getJson("/api/v1/applications/{$app['id']}")->assertOk();
    test()->withHeaders($owner)->getJson("/api/v1/applications/{$app['id']}/timeline")->assertOk();

    // And still nothing that is not theirs: the owner branch is unchanged.
    $ownerId = User::where('email', 'owner@biztrack.local')->value('id');
    $someoneElses = Application::where('applicant_user_id', '!=', $ownerId)->first();
    if ($someoneElses !== null) {
        expect(listedApplicationIds('owner@biztrack.local'))->not->toContain($someoneElses->id);
    }
    expect(Application::where('applicant_user_id', $ownerId)->pluck('id')->all())
        ->toEqualCanonicalizing(listedApplicationIds('owner@biztrack.local'));
});

it('gives an officer with no department nothing rather than everything', function () {
    // Fails closed: a reviewing account whose department was cleared must not
    // fall through into the unscoped branch.
    $orphan = User::where('email', 'sanitary@biztrack.local')->firstOrFail();
    $orphan->update(['department_id' => null]);

    expect(listedApplicationIds('sanitary@biztrack.local'))->toBe([]);

    $anyApplication = Application::firstOrFail();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$anyApplication->id}")
        ->assertForbidden();
});

it('does not widen what an officer may write on an office form', function () {
    // Item 56 scopes who may reach the form; it must not relax item 54's split
    // between the applicant's answers and the office's issuance dates.
    $app = fileRoutedApplication('Scoping Office Form Cafe', ['BUSINESS', 'OCCUPANCY']);

    test()->withHeaders(authAs('obo@biztrack.local'))
        ->putJson("/api/v1/applications/{$app['id']}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_date' => '2026-01-05', 'owner_name' => 'Hacked'],
        ])->assertOk();

    $stored = Application::find($app['id'])->officeForms()->first()->form_data;

    expect($stored['building_permit_date'])->toBe('2026-01-05')
        ->and($stored)->not->toHaveKey('owner_name');
});

it('still lets the assignment queue and inspections stay department-scoped', function () {
    $app = fileRoutedApplication('Scoping Queue Cafe', ['BUSINESS', 'SANITARY']);
    $bfp = Department::where('code', 'BFP')->value('id');

    $queue = test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson('/api/v1/assignments')->assertOk()->json('data');

    expect(collect($queue)->pluck('application.id'))->not->toContain($app['id'])
        ->and(collect($queue)->pluck('department.id')->unique()->filter()->all())
        ->each->toBe($bfp);
});

it('does not turn a business the officer may not see into a 500', function () {
    // Businesses with no application at all: the scope must simply refuse.
    $orphanBusiness = Business::whereDoesntHave('applications')->first();

    if ($orphanBusiness === null) {
        $owner = authAs('owner@biztrack.local');
        $orphanBusiness = Business::find(
            test()->withHeaders($owner)->postJson('/api/v1/businesses', [
                'name' => 'Scoping Orphan Store',
                'registration_type' => 'DTI',
                'registration_number' => 'DTI-70001',
                'tin' => '123-456-789-000',
                'address' => ['line1' => '9 Orphan Road', 'barangay_id' => Barangay::first()->id],
                'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 1000]],
            ])->assertCreated()->json('data.id')
        );
    }

    test()->withHeaders(authAs('market@biztrack.local'))
        ->getJson("/api/v1/businesses/{$orphanBusiness->id}")
        ->assertForbidden();
});

/*
 * ── Checklist item 111 ───────────────────────────────────────────────────────
 *
 * Item 56 (above) drew the boundary between FILINGS: an office reads the
 * applications it was routed and no others. Every case above proves that with
 * two separate applications, one per office, and all of them passed while the
 * leak the client reported was still wide open.
 *
 * The reason is the shape of a real filing. WorkflowService::routeToDepartments
 * creates one assignment per office that issues a requested permit type, so an
 * ordinary six-clearance application is routed to six offices at once and all
 * six are legitimately "part of" it. Everything hung off that application —
 * requirement requests, message turns, office form sheets — was then shared
 * between them, and each office read the other five's work. Against the tester
 * register the sanitary officer's requirements list came back 37 rows from BPLO,
 * 21 from the fire office and 10 from planning against 32 of its own.
 *
 * So these cases all use ONE application routed to TWO offices. That is the
 * arrangement item 56's cases could not produce and the one the client actually
 * has.
 */

/** One filing routed to both the City Health Office and the fire office. */
function sharedFiling(string $name): array
{
    return fileRoutedApplication($name, ['BUSINESS', 'SANITARY', 'FSIC']);
}

/**
 * Put real answers on these offices' sheets.
 *
 * Written straight to the table rather than through PUT /office-forms because
 * the owner may only write while the filing is a draft or returned, and
 * sharedFiling() has already submitted and paid — which is exactly the state the
 * officer review sheet is read in. What is under test is which stored sheets a
 * reader is handed, not the write path, which OfficeScopingTest already covers
 * two cases below.
 *
 * @param  list<string>  $permitCodes
 */
function storeOfficeSheets(int $applicationId, array $permitCodes): void
{
    foreach ($permitCodes as $code) {
        ApplicationOfficeForm::create([
            'application_id' => $applicationId,
            'permit_type_id' => PermitType::where('code', $code)->value('id'),
            // Keyed by code so a leak names the office it came from in the
            // failure output, rather than just a count that is one too high.
            'form_data' => ['establishment_name' => "{$code} answers"],
        ]);
    }
}

it('hides another office’s requirement on a filing both offices share', function () {
    $app = sharedFiling('Item111 Requests Cafe');

    $theirs = test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/requests", [
            'request_type' => 'document', 'title' => 'Fire safety plan',
        ])->assertCreated()->json('data.id');

    $mine = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/requests", [
            'request_type' => 'document', 'title' => 'Water potability result',
        ])->assertCreated()->json('data.id');

    $seen = collect(
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data')
    )->pluck('id');

    // Both offices are on this filing, so this is the case item 56 could not reach.
    expect($seen)->toContain($mine)
        ->and($seen)->not->toContain($theirs);
});

it('will not let one office close another office’s requirement on a shared filing', function () {
    $app = sharedFiling('Item111 Close Cafe');

    $theirs = test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/requests", [
            'request_type' => 'document', 'title' => 'Fire safety plan',
        ])->assertCreated()->json('data.id');

    // Sanitary may READ this filing — it is routed to them — and still may not
    // decide whether the fire office's requirement was met.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$theirs}/close", ['outcome' => 'fulfilled'])
        ->assertForbidden();

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/requests/{$theirs}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
});

it('shows an office only its own form sheet on a filing both offices share', function () {
    $app = sharedFiling('Item111 Forms Cafe');

    $codes = fn (string $email) => collect(
        test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$app['id']}/office-forms")->assertOk()->json('data')
    )->pluck('permit_type_code');

    // The client's example: a CHO officer must not read the FSIC sheet.
    expect($codes('sanitary@biztrack.local'))->toContain('SANITARY')
        ->and($codes('sanitary@biztrack.local'))->not->toContain('FSIC');
    expect($codes('fire@biztrack.local'))->toContain('FSIC')
        ->and($codes('fire@biztrack.local'))->not->toContain('SANITARY');

    // The applicant wrote every one of these sheets and keeps all of them.
    expect($codes('owner@biztrack.local'))->toContain('SANITARY')->toContain('FSIC');
});

/*
 * The case above asserts the right thing about the WRONG ENDPOINT (SEP-1).
 *
 * It only ever calls `GET /applications/{id}/office-forms`, and it has passed
 * since item 111 shipped. The officer review sheet does not use that endpoint.
 * It reads office forms out of the assignment payload — `GET /assignments/{id}`
 * → ApplicationResource — which carried no filter at all, so the one screen the
 * client actually approves filings on was the one screen the fix never reached.
 * Same reader, same filing, two endpoints, two answers.
 *
 * Verified against the register before the fix: `sanitary@` on `/assignments/10`
 * received SANITARY *and* BFP's FSIC, and on a seven-office filing the CHO
 * officer's payload carried CENRO's `owner_birthday` — a date of birth, rendered
 * eight sections below an RA 10173 consent notice.
 *
 * `grep office_forms api/tests/` returned nothing before these two cases
 * existed, which is the lesson: the endpoint an assertion names matters as much
 * as what it asserts, and a regression test for a read leak has to be pointed at
 * the payload the browser actually loads.
 */
it('shows an office only its own form sheet in the assignment payload it actually loads', function () {
    $app = sharedFiling('Item111 Assignment Payload Cafe');
    storeOfficeSheets($app['id'], ['SANITARY', 'FSIC']);

    // The codes THIS office reads off the assignment it opens in its own queue.
    $codesOn = function (string $email, string $departmentCode) use ($app) {
        $assignmentId = ApplicationAssignment::where('application_id', $app['id'])
            ->whereHas('department', fn ($d) => $d->where('code', $departmentCode))
            ->value('id');

        return collect(
            test()->withHeaders(authAs($email))
                ->getJson("/api/v1/assignments/{$assignmentId}")
                ->assertOk()
                ->json('data.application.office_forms')
        )->pluck('permit_type_code');
    };

    // The client's example — "sanitary accounts can only see sanitary permits,
    // and fire accounts can only see fire" — on the endpoint that leaked it.
    expect($codesOn('sanitary@biztrack.local', 'CHO'))->toContain('SANITARY')
        ->and($codesOn('sanitary@biztrack.local', 'CHO'))->not->toContain('FSIC');
    expect($codesOn('fire@biztrack.local', 'BFP'))->toContain('FSIC')
        ->and($codesOn('fire@biztrack.local', 'BFP'))->not->toContain('SANITARY');

    // BPLO holds application.view_any_office and coordinates the other offices,
    // so it keeps every sheet. Closing the leak must not close this.
    expect($codesOn('bplo@biztrack.local', 'BPLO'))
        ->toContain('SANITARY')->toContain('FSIC');
});

it('keeps the applicant’s shared particulars on every office’s review sheet', function () {
    /*
     * The other half of the fix, and the half that is easy to break next.
     *
     * "SANITARY PERMIT ONLY" cannot be taken literally: it would delete the
     * address and barangay (the inspector could not then find the premises), the
     * PSIC line of business (CENRO reviews exactly that), the uploaded
     * requirements and the floor area CPDO's fee is computed from. Those are the
     * APPLICANT's own particulars, not another office's file. Only the
     * per-office questionnaires are scoped — if a later change starts stripping
     * the shared sheet as well, this goes red and should.
     */
    $app = sharedFiling('Item111 Shared Sheet Cafe');

    $assignmentId = ApplicationAssignment::where('application_id', $app['id'])
        ->whereHas('department', fn ($d) => $d->where('code', 'CHO'))
        ->value('id');

    $payload = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/assignments/{$assignmentId}")->assertOk()->json('data.application');

    expect($payload['business']['address']['line1'])->not->toBeEmpty()
        ->and($payload['business']['address']['barangay'])->not->toBeNull()
        ->and($payload['business']['lines'])->not->toBeEmpty()
        // The sanitary officer still needs to know this filing also carries
        // FSIC, even though the FSIC ANSWERS are not theirs to read.
        ->and(collect($payload['permit_types'])->pluck('code'))->toContain('FSIC');
});

it('will not let an office record an issuance date on another office’s sheet', function () {
    $app = sharedFiling('Item111 Form Write Cafe');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->putJson("/api/v1/applications/{$app['id']}/office-forms/FSIC", [
            'form_data' => ['date_issued' => '2026-01-05'],
        ])->assertForbidden();

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->putJson("/api/v1/applications/{$app['id']}/office-forms/FSIC", [
            'form_data' => ['date_issued' => '2026-01-05'],
        ])->assertOk();
});

it('hides another office’s message turn but keeps the applicant’s', function () {
    $app = sharedFiling('Item111 Messages Cafe');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Applicant speaking'])
        ->assertCreated();
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Fire office speaking'])
        ->assertCreated();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Health office speaking'])
        ->assertCreated();

    $bodies = fn (string $email) => collect(
        test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$app['id']}/messages")->assertOk()->json('data')
    )->pluck('body');

    $sanitary = $bodies('sanitary@biztrack.local');
    expect($sanitary)->toContain('Applicant speaking')
        ->and($sanitary)->toContain('Health office speaking')
        ->and($sanitary)->not->toContain('Fire office speaking');

    // Cuts both ways, and the applicant still reads the whole conversation.
    expect($bodies('fire@biztrack.local'))->not->toContain('Health office speaking');
    expect($bodies('owner@biztrack.local'))
        ->toContain('Fire office speaking')->toContain('Health office speaking');
});

it('does not quote another office’s message in the inbox preview', function () {
    $app = sharedFiling('Item111 Inbox Cafe');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Applicant speaking'])
        ->assertCreated();
    // The newest turn overall belongs to the office that must stay hidden, so an
    // unscoped preview would put it straight on the other office's inbox row.
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Fire office speaking'])
        ->assertCreated();

    $row = collect(
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data')
    )->firstWhere('application_id', $app['id']);

    expect($row)->not->toBeNull()
        ->and($row['last_message']['body'])->toBe('Applicant speaking')
        // The counter is part of the leak: it must count only readable turns.
        ->and($row['messages_count'])->toBe(1);
});

it('refuses an attachment hanging off another office’s message', function () {
    $app = sharedFiling('Item111 Attachment Cafe');

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", [
            'body' => 'Fire office speaking',
            'attachment' => UploadedFile::fake()->create('fire.pdf', 8, 'application/pdf'),
        ])->assertCreated();

    $attachmentId = MessageAttachment::query()->latest('id')->value('id');

    // Hiding the message but still serving its file would leave the leak open
    // behind a guessable id.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->get("/api/v1/message-attachments/{$attachmentId}/download")
        ->assertForbidden();

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->get("/api/v1/message-attachments/{$attachmentId}/download")
        ->assertOk();
});

it('drops a filing out of an office’s approval queue once that office has approved', function () {
    $app = sharedFiling('Item111 Queue Cafe');

    // Confirmed on receipt, so CHO's sign-off below is refused by nothing. What
    // partitions the queue is the office's own assignment status, not the
    // processing category, and this case is about that partition.
    classifyAsOfficer(Application::findOrFail($app['id']));

    /*
     * `for_inspection` is in this filter now, and it has to be.
     *
     * What this case is about has not moved: the queue must partition on the
     * OFFICE's own assignment status, not on the filing's, because filtering on
     * the filing alone is what made the office that had just approved be shown
     * its own finished work again (item 111). What HAS moved is the filing's
     * status while that is true. WorkflowService::afterReviewProgress used to
     * hold every filing at `under_review` until the last office signed off; it
     * books each office's site visit the moment that office approves now, and
     * the filing reads `for_inspection` from the first booking onward — so CHO
     * approving here moves it while BFP still has a pending review.
     *
     * Leaving `for_inspection` out would have quietly turned the last assertion
     * into a tautology: BFP's row would have vanished from this list because of
     * the filing's status, not because of BFP's assignment, and the test would
     * be measuring the bug it was written to prevent.
     */
    $openIds = fn (string $email) => collect(
        test()->withHeaders(authAs($email))
            ->getJson('/api/v1/assignments?status=pending,in_progress,returned'
                .'&application_status=submitted,pending_payment,under_review,returned,for_inspection&per_page=200')
            ->assertOk()->json('data')
    )->pluck('application.id');

    expect($openIds('sanitary@biztrack.local'))->toContain($app['id']);

    $assignmentId = ApplicationAssignment::where('application_id', $app['id'])
        ->whereHas('department', fn ($d) => $d->where('code', 'CHO'))
        ->value('id');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignmentId}/approve", ['remarks' => 'Cleared.'])
        ->assertOk();

    /*
     * SANITARY is inspected, so CHO's approval books CHO's visit and the filing
     * moves — parallel, per office, rather than everyone waiting for the
     * slowest. The filing is genuinely not `under_review` any more: a site
     * visit is outstanding on it. What remains true, and is the whole point
     * here, is the line below it — the office that approved is done, and the
     * office that has not is not.
     */
    expect(Application::find($app['id'])->status->value)->toBe('for_inspection');
    expect($openIds('sanitary@biztrack.local'))->not->toContain($app['id']);
    expect($openIds('fire@biztrack.local'))->toContain($app['id']);
});

it('keeps the whole shared filing readable for BPLO — deliberately', function () {
    /*
     * The wide view is a workflow requirement, not an oversight: BPLO issues the
     * mayor's permit only once every other office has cleared its part, so it has
     * to be able to see what each of them asked for and said. If this case ever
     * fails, the scoping has been pushed one office too far.
     */
    $app = sharedFiling('Item111 BPLO Cafe');

    $theirs = test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/requests", [
            'request_type' => 'document', 'title' => 'Fire safety plan',
        ])->assertCreated()->json('data.id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Applicant speaking'])
        ->assertCreated();
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Fire office speaking'])
        ->assertCreated();

    $bplo = authAs('bplo@biztrack.local');

    expect(collect(test()->withHeaders($bplo)->getJson('/api/v1/requests?per_page=200')
        ->assertOk()->json('data'))->pluck('id'))->toContain($theirs);

    expect(collect(test()->withHeaders($bplo)
        ->getJson("/api/v1/applications/{$app['id']}/messages")->assertOk()->json('data'))
        ->pluck('body'))->toContain('Fire office speaking');

    expect(collect(test()->withHeaders($bplo)
        ->getJson("/api/v1/applications/{$app['id']}/office-forms")->assertOk()->json('data'))
        ->pluck('permit_type_code'))->toContain('SANITARY')->toContain('FSIC');
});

it('does not list an inbox row whose only turns belong to another office', function () {
    $app = sharedFiling('Item111 Silhouette Cafe');

    // Only the fire office has spoken. Sanitary can read the filing, so an
    // unscoped inbox would hand it a row with no messages in it — the leak
    // reduced to a silhouette: who spoke, and when, without the words.
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$app['id']}/messages", ['body' => 'Fire office speaking'])
        ->assertCreated();

    $rowFor = fn (string $email) => collect(
        test()->withHeaders(authAs($email))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data')
    )->firstWhere('application_id', $app['id']);

    expect($rowFor('sanitary@biztrack.local'))->toBeNull()
        ->and($rowFor('fire@biztrack.local'))->not->toBeNull()
        // BPLO coordinates and keeps the whole register, deliberately.
        ->and($rowFor('bplo@biztrack.local'))->not->toBeNull();
});
