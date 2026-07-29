<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\Payment;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

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
