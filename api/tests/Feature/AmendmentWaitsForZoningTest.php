<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * An amendment that moves the premises waits for its Zoning Clearance.
 *
 * Client, 10 November 2026, choosing "Waiting for Zoning instead of an
 * error": BPLO's review says what the amendment waits for and where the
 * sheet stands, BPLO is told when CPDO issues it, and the owner is reminded
 * once if the sheet is not handed in. The refusal in `approveAmendment`
 * stays as the safety net.
 *
 * And the client's report the same day: *"the submission of that does not
 * show in the admin side that a new zoning clearance application has been
 * submitted."* The CPDO half is pinned here — the handed-in sheet is on
 * CPDO's Manage Applications and CPDO is told — on both ways a move reaches
 * an amendment: at submission, and added after BPLO returns it.
 *
 * Helpers are prefixed `zw`: Pest helpers share one namespace.
 */
beforeEach(fn () => duringOfficeHours());

/** A submitted amendment; a move unless other changes are given. */
function zwAmendment(array $changes = [
    ['field' => 'address_street', 'new_value' => 'Rizal Avenue'],
    ['field' => 'address_pin', 'new_value' => '14.6600,120.9500'],
]): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Zoning Wait '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Old Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 200000]],
    ])->assertCreated()->json('data.id');

    // The submit gate refuses an amendment naming no permit to amend.
    $earlier = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => User::where('email', 'owner@biztrack.local')->value('id'),
        'application_type' => 'new',
        'status' => 'approved',
    ]);
    $prior = Permit::create([
        'application_id' => $earlier->id,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
        'permit_number' => 'ZW-'.random_int(10000, 99999),
        'issued_at' => now()->subMonths(2),
        'valid_from' => now()->subMonths(2),
        'valid_until' => now()->addMonths(4),
        'status' => 'active',
    ]);

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'amendment',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'prior_permit_id' => $prior->id,
        'amendment_other' => 'Moved to a bigger unit.',
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/amendments", [
        'changes' => $changes,
    ])->assertOk();

    attachRequiredDocuments($appId);
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId);
}

/** The owner hands in the Zoning sheet from Permit Tracking. */
function zwHandIn(Application $app): void
{
    satisfyChecklist($app, 'ZONING');
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/ZONING", ['form_data' => [], 'submit' => true])
        ->assertSuccessful();
}

function zwReview(Application $app, string $office): ApplicationAssignment
{
    return ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', $office)->value('id'))
        ->firstOrFail();
}

/** What BPLO's review payload says the amendment waits for. */
function zwWaitingFor(Application $app): ?array
{
    return test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/assignments/'.zwReview($app, 'BPLO')->id)
        ->assertOk()
        ->json('data.application.amendment_waiting_for');
}

/** CPDO's Manage Applications, For Approval stage, as QueuePage asks for it. */
function zwCpdoQueue(): array
{
    return collect(test()->withHeaders(authAs('zoning@biztrack.local'))
        ->getJson('/api/v1/assignments?application_status=for_approval,returned,approved'
            .'&application_decided=0&status=pending,in_progress,returned')
        ->assertOk()
        ->json('data'))
        ->pluck('application.id')
        ->all();
}

/** CPDO accepts the sheet and passes the visit, which issues the clearance. */
function zwCpdoIssues(Application $app): void
{
    $cpdo = authAs('zoning@biztrack.local');
    test()->withHeaders($cpdo)
        ->postJson('/api/v1/assignments/'.zwReview($app, 'CPDO')->id.'/approve')->assertOk();
    $visitId = test()->withHeaders($cpdo)
        ->postJson("/api/v1/applications/{$app->id}/permits/ZONING/inspection", ['scheduled_at' => now()->toDateTimeString()])
        ->assertCreated()->json('data.id');
    test()->withHeaders($cpdo)
        ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'] + oboNames($visitId))->assertOk();
}

function zwNotices(string $email, string $title): array
{
    return AppNotification::where('user_id', User::where('email', $email)->value('id'))
        ->where('title', $title)
        ->get(['body', 'link'])
        ->toArray();
}

it('lists a move’s handed-in Zoning sheet on CPDO’s queue and tells CPDO', function () {
    $app = zwAmendment();

    // Applied for at submission, not handed in: nothing for CPDO to read yet.
    expect(zwCpdoQueue())->not->toContain($app->id);

    zwHandIn($app);

    expect(zwCpdoQueue())->toContain($app->id)
        ->and(zwNotices('zoning@biztrack.local', 'New application'))->toHaveCount(1)
        ->and(zwNotices('zoning@biztrack.local', 'New application')[0]['link'])
        ->toBe('/staff/queue/'.zwReview($app, 'CPDO')->id);
});

it('lists a Zoning sheet on CPDO’s queue when the move was added after BPLO returned the amendment', function () {
    $app = zwAmendment([['field' => 'trade_name', 'new_value' => 'Before Return']]);
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/assignments/'.zwReview($app, 'BPLO')->id.'/return', ['remarks' => 'Please check the address.'])
        ->assertOk();

    $owner = authAs('owner@biztrack.local');
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/amendments", [
        'changes' => [
            ['field' => 'address_street', 'new_value' => 'Moved Street'],
            ['field' => 'address_pin', 'new_value' => '14.6600,120.9500'],
        ],
    ])->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/resubmit")->assertOk();

    // Permit Tracking: Apply opens the sheet, then the owner hands it in.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertSuccessful();
    zwHandIn($app);

    expect(zwCpdoQueue())->toContain($app->id)
        ->and(zwNotices('zoning@biztrack.local', 'New application'))->toHaveCount(1);
});

it('tells BPLO’s review what the amendment waits for, and BPLO when it is ready to approve', function () {
    $app = zwAmendment();

    expect(zwWaitingFor($app))->toBe([
        ['code' => 'ZONING', 'name' => 'Zoning Clearance', 'office' => 'CPDO', 'state' => 'not_handed_in'],
    ]);

    zwHandIn($app);
    expect(zwWaitingFor($app)[0]['state'])->toBe('with_office');

    // The refusal stays as the safety net while it waits.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/assignments/'.zwReview($app, 'BPLO')->id.'/approve')
        ->assertStatus(422);
    expect(zwNotices('bplo@biztrack.local', 'Zoning Clearance issued — ready to approve'))->toBeEmpty();

    zwCpdoIssues($app);

    $name = $app->business->name;
    expect(zwWaitingFor($app))->toBe([])
        ->and(zwNotices('bplo@biztrack.local', 'Zoning Clearance issued — ready to approve'))->toBe([[
            'body' => "{$app->tracking_id} · {$name} can now be approved.",
            'link' => '/staff/queue/'.zwReview($app, 'BPLO')->id,
        ]]);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/assignments/'.zwReview($app, 'BPLO')->id.'/approve')
        ->assertOk();
});

it('waits for nothing on an amendment that carries no clearance', function () {
    $app = zwAmendment([['field' => 'trade_name', 'new_value' => 'Just A Name']]);

    expect(zwWaitingFor($app))->toBe([]);
});

it('reminds the owner once to hand in the Zoning sheet, three days after submitting', function () {
    $app = zwAmendment();
    $title = 'Hand in your Zoning Clearance';

    test()->artisan('biztrack:scan-permits')->assertSuccessful();
    expect(zwNotices('owner@biztrack.local', $title))->toBeEmpty();

    test()->travel(4)->days();
    test()->artisan('biztrack:scan-permits')->assertSuccessful();
    test()->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(zwNotices('owner@biztrack.local', $title))->toBe([[
        'body' => "{$app->tracking_id}: BPLO can approve your amendment once the Zoning Clearance is issued.",
        'link' => "/applications/{$app->id}/clearances",
    ]]);
});

it('does not remind an owner who has handed the Zoning sheet in', function () {
    $app = zwAmendment();
    zwHandIn($app);

    test()->travel(4)->days();
    test()->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(zwNotices('owner@biztrack.local', 'Hand in your Zoning Clearance'))->toBeEmpty();
});

/*
 * Option 2 of the "does not show in the admin side" report, client, 10
 * November 2026: BPLO hears when the owner hands the Zoning sheet in.
 */
it('tells BPLO once when the Zoning sheet it waits for is handed in', function () {
    $app = zwAmendment();
    $title = 'Zoning Clearance handed in — with CPDO';

    zwHandIn($app);
    // CPDO accepting the sheet tells BPLO nothing more.
    test()->withHeaders(authAs('zoning@biztrack.local'))
        ->postJson('/api/v1/assignments/'.zwReview($app, 'CPDO')->id.'/approve')->assertOk();

    expect(zwNotices('bplo@biztrack.local', $title))->toBe([[
        'body' => "{$app->tracking_id} · {$app->business->name} handed in the Zoning Clearance.",
        'link' => '/staff/queue/'.zwReview($app, 'BPLO')->id,
    ]]);
});

it('tells BPLO again when the Zoning sheet comes back after CPDO returns it', function () {
    $app = zwAmendment();
    zwHandIn($app);

    test()->withHeaders(authAs('zoning@biztrack.local'))
        ->postJson('/api/v1/assignments/'.zwReview($app, 'CPDO')->id.'/return', ['remarks' => 'Please check the sketch.'])
        ->assertOk();
    zwHandIn($app);

    expect(zwNotices('bplo@biztrack.local', 'Zoning Clearance handed in — with CPDO'))->toHaveCount(2);
});

it('does not tell BPLO about a new filing’s Zoning sheet', function () {
    $owner = authAs('owner@biztrack.local');
    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Zoning Wait New '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 New Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');
    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');
    $app = attachRequiredDocuments($appId);
    test()->withHeaders(authAs('owner@biztrack.local'))->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($app->fresh());
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/clearances/ZONING/apply")->assertSuccessful();
    zwHandIn(Application::findOrFail($appId));

    expect(zwNotices('zoning@biztrack.local', 'New application'))->toHaveCount(1)
        ->and(zwNotices('bplo@biztrack.local', 'Zoning Clearance handed in — with CPDO'))->toBeEmpty();
});
