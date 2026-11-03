<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Jobs\SendOwnerUpdateEmail;
use App\Jobs\SendSms;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The office whose turn it is hears that an applicant has submitted.
 *
 * Thesis panel revision (Tool Defense matrix, item 8): "Added notifications
 * for officers. The office handling an application is notified when an
 * applicant submits it." Ken's rule for who hears it:
 *
 *   a new application, a Business Permit renewal, an amendment   BPLO
 *   an office's sheet handed in after payment                     that office
 *   a clearance-only renewal, which BPLO never sees               that office
 *
 * Inside the office, the officer holding the case if one does, otherwise
 * every active account there. Never the super admin, another office or the
 * applicant. In the bell only — no e-mail, no text. And a filing or an office
 * sheet sent back and resubmitted is not announced as new: the officer who
 * returned it is told "Corrections received" instead — for a sheet, since
 * Ken's approval of 21 October 2026, by the same who-hears-it rule.
 *
 * Helpers are prefixed `turn`: Pest helpers share one namespace across the
 * suite, and a generic name here would collide with someone else's.
 */

/** The notices of one title an account has received, oldest first. */
function turnNotices(User|string $reader, string $title = 'New application'): Collection
{
    $reader = $reader instanceof User ? $reader : turnAccount($reader);

    return AppNotification::where('user_id', $reader->id)
        ->where('title', $title)
        ->orderBy('id')
        ->get();
}

function turnAccount(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

/**
 * A second account in an office. It takes the roles of the office's seeded
 * officer unless a role is named — the super admin's, for the case of one
 * seated in an office.
 */
function turnSeat(string $code, string $email, bool $active = true, ?string $role = null): User
{
    $departmentId = Department::where('code', $code)->value('id');
    $roles = $role !== null
        ? Role::where('name', $role)->pluck('id')
        : User::where('department_id', $departmentId)->orderBy('id')->firstOrFail()->roles()->pluck('roles.id');

    $seat = User::create([
        'name' => "Seat {$email}", 'first_name' => 'Seat', 'last_name' => 'Account', 'gender' => 'F',
        'email' => $email, 'password' => 'biztrack1',
        // A real mobile, so a text sent to staff would actually be queued.
        'mobile_number' => '0917'.random_int(1000000, 9999999),
        'department_id' => $departmentId, 'is_active' => $active, 'email_verified_at' => now(),
    ]);
    $seat->roles()->sync($roles);

    return $seat;
}

/** A new application of owner@, saved as a draft with its documents, not yet submitted. */
function turnDraft(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Bell Test Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Bell Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    return attachRequiredDocuments($appId);
}

/** The owner presses Submit. */
function turnSubmit(Application $app): Application
{
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    return $app->fresh();
}

/** One office's review of this filing — the row its queue lists. */
function turnReview(Application $app, string $code): ApplicationAssignment
{
    return ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', $code)->value('id'))
        ->firstOrFail();
}

/** A new filing BPLO has approved (all five permits ticked) and the owner has paid. */
function turnPaid(): Application
{
    $app = turnSubmit(turnDraft());
    bploApprovesForm($app);

    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    return $app->fresh();
}

/** The owner applies for one permit and hands its office sheet in. */
function turnHandIn(Application $app, string $code): void
{
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/clearances/{$code}/apply")->assertSuccessful();
    satisfyChecklist($app->fresh(), $code);
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/{$code}", [
        'form_data' => [],
        'submit' => true,
    ])->assertSuccessful();
}

/**
 * A renewal draft of owner@'s live permits of these codes, inside the
 * renewal window. Submitted at the service, as ClearanceRenewalReachesItsOfficeTest
 * does, so a failure points at the transition rather than at a form gate.
 *
 * @param  list<string>  $codes
 */
function turnRenewal(array $codes): Application
{
    $owner = turnAccount('owner@biztrack.local');

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Bell Renewal '.random_int(10000, 99999),
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-2026-'.random_int(100000, 999999),
        'barangay_id' => Barangay::value('id'),
        'address_line' => '9 Renewal Street',
        'status' => 'active',
    ]);
    $earlier = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $permits = collect($codes)->map(fn (string $code) => Permit::create([
        'application_id' => $earlier->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'permit_number' => "BELL-{$code}-".uniqid(),
        'issued_at' => CarbonImmutable::now()->subYear(),
        'valid_from' => CarbonImmutable::now()->subYear(),
        'valid_until' => CarbonImmutable::now()->addDays(10),
        'status' => 'active',
    ]));

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'data_privacy_consent' => true,
        'prior_permit_id' => $permits->first()->id,
    ]);
    $app->priorPermits()->sync($permits->pluck('id')->all());
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id')->all());

    return $app->fresh();
}

/** An amendment draft that moves the premises, so it carries the Zoning Clearance. */
function turnMove(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Bell Move '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Old Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 200000]],
    ])->assertCreated()->json('data.id');

    // The submit gate refuses an amendment naming no permit to amend.
    $earlier = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => turnAccount('owner@biztrack.local')->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);
    $prior = Permit::create([
        'application_id' => $earlier->id,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
        'permit_number' => 'BELL-MOVE-'.random_int(10000, 99999),
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
        'changes' => [
            ['field' => 'address_street', 'new_value' => 'Rizal Avenue'],
            ['field' => 'address_pin', 'new_value' => '14.6600,120.9500'],
        ],
    ])->assertOk();

    return attachRequiredDocuments($appId);
}

/** Each office's permit, and the account the seed puts in that office. */
dataset('turn offices', [
    'Sanitary Permit — CHO' => ['SANITARY', 'CHO', 'sanitary@biztrack.local'],
    'FSIC — BFP' => ['FSIC', 'BFP', 'fire@biztrack.local'],
    'Zoning Clearance — CPDO' => ['ZONING', 'CPDO', 'zoning@biztrack.local'],
    'Occupancy Permit — OBO' => ['OCCUPANCY', 'OBO', 'obo@biztrack.local'],
    'CEC — CENRO' => ['CEC', 'CENRO', 'cenro@biztrack.local'],
]);

const TURN_OFFICE_ACCOUNTS = [
    'sanitary@biztrack.local', 'fire@biztrack.local', 'zoning@biztrack.local',
    'obo@biztrack.local', 'cenro@biztrack.local',
];

it('tells every active BPLO account of a new application, and nobody else', function () {
    $second = turnSeat('BPLO', 'bplo.second@biztrack.local');
    $retired = turnSeat('BPLO', 'bplo.retired@biztrack.local', active: false);
    // The super admin seated in BPLO: the worst case for "never the super admin".
    $seatedAdmin = turnSeat('BPLO', 'admin.in.bplo@biztrack.local', role: 'admin');

    $app = turnSubmit(turnDraft());
    $review = turnReview($app, 'BPLO');

    foreach (['bplo@biztrack.local', $second] as $reader) {
        $notices = turnNotices($reader);
        expect($notices)->toHaveCount(1)
            ->and($notices->first()->body)->toBe("{$app->tracking_id} · {$app->business->name} was submitted.")
            ->and($notices->first()->link)->toBe("/staff/queue/{$review->id}");
    }

    foreach ([$retired, $seatedAdmin, 'admin@biztrack.local', 'owner@biztrack.local', ...TURN_OFFICE_ACCOUNTS] as $nobody) {
        expect(turnNotices($nobody))->toBeEmpty();
    }
});

it('tells only the officer holding the case when one already does', function () {
    $holder = turnSeat('BPLO', 'bplo.holder@biztrack.local');
    $app = turnDraft();
    // BPLO is already on this filing, and one of its officers holds it.
    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'BPLO')->value('id'),
        'officer_user_id' => $holder->id,
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    turnSubmit($app);

    expect(turnNotices($holder))->toHaveCount(1)
        ->and(turnNotices('bplo@biztrack.local'))->toBeEmpty();
});

it('tells the whole office when the officer holding the case has been deactivated', function () {
    $holder = turnSeat('BPLO', 'bplo.gone@biztrack.local');
    $app = turnDraft();
    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'BPLO')->value('id'),
        'officer_user_id' => $holder->id,
        'status' => 'pending',
        'assigned_at' => now(),
    ]);
    $holder->update(['is_active' => false]);

    turnSubmit($app);

    expect(turnNotices($holder))->toBeEmpty()
        ->and(turnNotices('bplo@biztrack.local'))->toHaveCount(1);
});

it('tells BPLO when the Business Permit is renewed', function () {
    $app = turnRenewal([PermitType::OUTCOME_CODE]);
    app(WorkflowService::class)->submit($app);
    $app = $app->fresh();

    $notices = turnNotices('bplo@biztrack.local');
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toBe("{$app->tracking_id} · {$app->business->name} was submitted.")
        ->and($notices->first()->link)->toBe('/staff/queue/'.turnReview($app, 'BPLO')->id);

    foreach (TURN_OFFICE_ACCOUNTS as $nobody) {
        expect(turnNotices($nobody))->toBeEmpty();
    }
});

it('tells BPLO of an amendment, and CPDO once the move’s Zoning Clearance is handed in', function () {
    $app = turnSubmit(turnMove());
    $name = $app->business->name;

    expect(turnNotices('bplo@biztrack.local'))->toHaveCount(1)
        ->and(turnNotices('bplo@biztrack.local')->first()->body)->toBe("{$app->tracking_id} · {$name} was submitted.");

    /*
     * Submitting applies for the Zoning Clearance and stops: CPDO is not on
     * the filing until the owner hands its sheet in (WorkflowService::submit),
     * so it could not yet open one.
     */
    expect(turnNotices('zoning@biztrack.local'))->toBeEmpty();

    satisfyChecklist($app, 'ZONING');
    authAs('owner@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/ZONING", ['form_data' => [], 'submit' => true])
        ->assertSuccessful();

    $notices = turnNotices('zoning@biztrack.local');
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toBe("{$app->tracking_id} · {$name} applied for the Zoning Clearance.")
        ->and($notices->first()->link)->toBe('/staff/queue/'.turnReview($app, 'CPDO')->id)
        // BPLO heard of the amendment once.
        ->and(turnNotices('bplo@biztrack.local'))->toHaveCount(1);
});

it('tells the office whose permit the owner applies for after paying, and only that office', function (string $code, string $office, string $email) {
    $app = turnPaid();
    expect(turnNotices('bplo@biztrack.local'))->toHaveCount(1);

    turnHandIn($app, $code);

    $permit = PermitType::where('code', $code)->value('name');
    $notices = turnNotices($email);
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toBe("{$app->tracking_id} · {$app->business->name} applied for the {$permit}.")
        ->and($notices->first()->link)->toBe('/staff/queue/'.turnReview($app, $office)->id)
        // BPLO is not told a second time about a permit that is not its own.
        ->and(turnNotices('bplo@biztrack.local'))->toHaveCount(1);

    foreach (array_diff(TURN_OFFICE_ACCOUNTS, [$email]) as $nobody) {
        expect(turnNotices($nobody))->toBeEmpty();
    }
})->with('turn offices');

it('tells the issuing office of a clearance-only renewal, and never BPLO', function (string $code, string $office, string $email) {
    $app = turnRenewal([$code]);
    // The sheet's checklist is complete, as the wizard requires before Submit.
    satisfyChecklist($app, $code);
    app(WorkflowService::class)->submit($app->fresh());
    $app = $app->fresh();

    $permit = PermitType::where('code', $code)->value('name');
    $notices = turnNotices($email);
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toBe("{$app->tracking_id} · {$app->business->name} applied for the {$permit}.")
        ->and($notices->first()->link)->toBe('/staff/queue/'.turnReview($app, $office)->id)
        ->and(turnNotices('bplo@biztrack.local'))->toBeEmpty();
})->with('turn offices');

it('tells only the officer who refused a permit when the owner applies for it again', function () {
    $colleague = turnSeat('CHO', 'cho.colleague@biztrack.local');
    $app = turnPaid();
    turnHandIn($app, 'SANITARY');

    // Nobody held it yet, so both CHO accounts heard of it.
    expect(turnNotices('sanitary@biztrack.local'))->toHaveCount(1)
        ->and(turnNotices($colleague))->toHaveCount(1);

    // sanitary@ takes it by acting on it, approves the papers and refuses it.
    $review = turnReview($app, 'CHO');
    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$review->id}/approve")->assertOk();
    test()->postJson("/api/v1/assignments/{$review->id}/reject", [
        'reason' => 'The premises have no potable water connection.',
        'remedy' => 'Connect to the mains supply, then apply again.',
    ])->assertOk();

    turnHandIn($app, 'SANITARY');

    $holder = turnNotices('sanitary@biztrack.local');
    expect($holder)->toHaveCount(2)
        ->and($holder->last()->body)->toBe("{$app->tracking_id} · {$app->business->name} applied for the Sanitary Permit.")
        ->and(turnNotices($colleague))->toHaveCount(1);
});

it('tells staff in the bell only: no e-mail and no text', function () {
    Bus::fake([SendOwnerUpdateEmail::class, SendSms::class]);

    $app = turnPaid();
    turnHandIn($app, 'SANITARY');

    expect(turnNotices('bplo@biztrack.local'))->toHaveCount(1)
        ->and(turnNotices('sanitary@biztrack.local'))->toHaveCount(1);

    $staff = User::whereNotNull('department_id')
        ->orWhereHas('roles', fn ($r) => $r->where('name', 'admin'))
        ->pluck('id');
    Bus::assertNotDispatched(SendOwnerUpdateEmail::class, fn ($job) => $staff->contains($job->owner->id));
    Bus::assertNotDispatched(SendSms::class, fn ($job) => $staff->contains($job->userId));
});

it('does not announce a returned filing again when the owner resubmits it', function () {
    $colleague = turnSeat('BPLO', 'bplo.colleague@biztrack.local');
    $app = turnSubmit(turnDraft());

    $review = turnReview($app, 'BPLO');
    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/assignments/{$review->id}/return", ['remarks' => 'Send the lease contract.'])
        ->assertOk();

    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/resubmit")->assertOk();

    $bplo = turnAccount('bplo@biztrack.local');
    expect(turnNotices($bplo))->toHaveCount(1)
        ->and(turnNotices($bplo, 'Corrections received'))->toHaveCount(1)
        ->and(turnNotices($colleague))->toHaveCount(1);

    // BPLO's own form came back: no clearance office hears of it.
    foreach (TURN_OFFICE_ACCOUNTS as $nobody) {
        expect(turnNotices($nobody, 'Corrections received'))->toBeEmpty();
    }
});

it('tells the officer who returned a sheet that the corrections are in, and only them', function () {
    $colleague = turnSeat('CHO', 'cho.second@biztrack.local');
    $app = turnPaid();
    turnHandIn($app, 'SANITARY');

    // sanitary@ returns it, and holds the case by doing so.
    $review = turnReview($app, 'CHO');
    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$review->id}/return", ['remarks' => 'Name the water source.'])
        ->assertOk();

    authAs('owner@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", ['form_data' => [], 'submit' => true])
        ->assertSuccessful();

    expect(app(WorkflowService::class)->pivotFor($app, 'SANITARY')->status)->toBe(ClearanceStatus::ForApproval);

    $corrections = turnNotices('sanitary@biztrack.local', 'Corrections received');
    expect($corrections)->toHaveCount(1)
        ->and($corrections->first()->body)->toBe("Corrections received on {$app->tracking_id}. It is back with your office for review.")
        ->and($corrections->first()->link)->toBe("/staff/queue/{$review->id}")
        // A correction, not a second application.
        ->and(turnNotices('sanitary@biztrack.local'))->toHaveCount(1)
        ->and(turnNotices($colleague, 'Corrections received'))->toBeEmpty()
        // Not BPLO's main-form notice, and not BPLO's to hear.
        ->and(turnNotices('bplo@biztrack.local', 'Corrections received'))->toBeEmpty();
});

it('tells the office’s active officers when nobody holds the case a returned sheet comes back to', function () {
    $colleague = turnSeat('CHO', 'cho.active@biztrack.local');
    $retired = turnSeat('CHO', 'cho.retired@biztrack.local', active: false);
    $app = turnPaid();
    turnHandIn($app, 'SANITARY');

    $review = turnReview($app, 'CHO');
    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$review->id}/return", ['remarks' => 'Name the water source.'])
        ->assertOk();
    // The officer in charge puts the case back in the office's pool.
    app(WorkflowService::class)->releaseOfficer($review->fresh());

    authAs('owner@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", ['form_data' => [], 'submit' => true])
        ->assertSuccessful();

    expect(turnNotices('sanitary@biztrack.local', 'Corrections received'))->toHaveCount(1)
        ->and(turnNotices($colleague, 'Corrections received'))->toHaveCount(1)
        ->and(turnNotices($retired, 'Corrections received'))->toBeEmpty()
        ->and(turnNotices('admin@biztrack.local', 'Corrections received'))->toBeEmpty();

    foreach (['bplo@biztrack.local', ...array_diff(TURN_OFFICE_ACCOUNTS, ['sanitary@biztrack.local'])] as $nobody) {
        expect(turnNotices($nobody, 'Corrections received'))->toBeEmpty();
    }
});

it('tells the office of a permit with no sheet the moment it is applied for', function () {
    /*
     * The clearance set is data (ClearanceService::clearanceTypes): an LGU
     * that seeds another office gets another card without a code change. A
     * permit with no office sheet is submitted by Apply itself
     * (WorkflowService::startClearance), so that press is its hand-over.
     */
    $office = anaOffice('DSK');
    $officer = turnSeat('CHO', 'desk.officer@biztrack.local');
    $officer->update(['department_id' => $office['department_id']]);

    $app = turnPaid();
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/clearances/{$office['permit_code']}/apply")->assertSuccessful();

    $review = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $office['department_id'])->firstOrFail();
    $notices = turnNotices($officer);
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toBe("{$app->tracking_id} · {$app->business->name} applied for the Test Permit DSK.")
        ->and($notices->first()->link)->toBe("/staff/queue/{$review->id}");
});

it('keeps the submission when the notice cannot be written', function () {
    /*
     * Written after the submission commits, inside a try: a bell that fails
     * must neither undo a filing the owner has been shown as submitted nor
     * answer them with a 500.
     */
    $this->partialMock(NotificationService::class, function ($mock) {
        $mock->shouldReceive('filingSubmitted')->andThrow(new RuntimeException('The bell is down.'));
    });
    Log::spy();

    $app = turnSubmit(turnDraft());

    expect($app->status)->toBe(ApplicationStatus::ForApproval)
        ->and(turnReview($app, 'BPLO')->exists)->toBeTrue()
        ->and(turnNotices('bplo@biztrack.local'))->toBeEmpty();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'An office was not told about a filing')
        ->once();
});

it('tells no office about a submission that was rolled back', function () {
    $app = turnDraft();

    try {
        DB::transaction(function () use ($app) {
            app(WorkflowService::class)->submit($app);
            throw new RuntimeException('The request failed after the filing moved.');
        });
    } catch (RuntimeException) {
        // The point: the filing did not move, so nobody may hear that it did.
    }

    expect($app->fresh()->status->value)->toBe('draft')
        ->and(turnNotices('bplo@biztrack.local'))->toBeEmpty();
});
