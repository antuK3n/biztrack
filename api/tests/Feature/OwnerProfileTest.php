<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\FeeAssessment;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;

/*
 * Business Owners and the owner profile [client, 20 October 2026]: every
 * office has the list and the profile, and each office sees only the owners,
 * businesses, filings and payments on its own caseload. BPLO and the super
 * admin see everyone.
 */

/**
 * A fresh owner with one business, one submitted filing routed to nobody, a
 * Mayor's Permit and a Sanitary Permit, and two payments — one paid, one not.
 *
 * @return array{owner: User, business: Business, app: Application, mayors: Permit, sanitary: Permit}
 */
function profileOwner(): array
{
    $owner = User::forceCreate([
        'first_name' => 'Profilia',
        'last_name' => 'Santos',
        'name' => 'Profilia Santos',
        'email' => 'profilia.'.uniqid().'@example.test',
        'mobile_number' => '09170000001',
        'password' => bcrypt('biztrack1'),
        'is_active' => true,
    ]);

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Profile Test Store '.uniqid(),
        'registration_type' => 'DTI',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '1 Profile Street',
        'status' => 'active',
    ]);

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
        'tracking_id' => 'BIZ-PROFILE-'.random_int(10000, 99999),
        'submitted_at' => now()->subMonth(),
        'decided_at' => now()->subWeeks(3),
    ]);

    $permit = fn (string $code, string $from, string $until) => Permit::create([
        'application_id' => $app->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'permit_number' => $code.'-PROFILE-'.uniqid(),
        'issued_at' => $from,
        'valid_from' => $from,
        'valid_until' => $until,
        'status' => 'active',
    ]);

    $mayors = $permit(PermitType::OUTCOME_CODE, '2026-09-20', '2026-12-31');
    $sanitary = $permit('SANITARY', '2026-09-22', '2027-09-22');

    $fee = FeeAssessment::create([
        'application_id' => $app->id,
        'line_items' => [
            ['label' => 'Business permit fee', 'amount' => 2100.5, 'group' => 'regulatory', 'office' => 'BPLO'],
            ['label' => 'Sanitary permit fee', 'amount' => 300.0, 'group' => 'regulatory', 'permit_codes' => ['SANITARY']],
        ],
        'total_amount' => 2400.5,
    ]);
    foreach ([['completed', 1500.50], ['pending', 900]] as $i => [$status, $amount]) {
        Payment::create([
            'application_id' => $app->id,
            'fee_assessment_id' => $fee->id,
            'reference_number' => 'PAY-PROFILE-'.$i.'-'.uniqid(),
            'amount' => $amount,
            'method' => 'gcash',
            'status' => $status,
            'paid_at' => $status === 'completed' ? now()->subWeeks(3) : null,
        ]);
    }

    return compact('owner', 'business', 'app', 'mayors', 'sanitary');
}

function ownerListIds(string $email): array
{
    return collect(test()->withHeaders(authAs($email))
        ->getJson('/api/v1/owner-profiles?per_page=100')->assertOk()->json('data'))
        ->pluck('id')->all();
}

it('shows BPLO every owner, with the profile the screen draws', function () {
    ['owner' => $owner, 'business' => $business, 'sanitary' => $sanitary] = profileOwner();

    expect(ownerListIds('bplo@biztrack.local'))->toContain($owner->id);

    $data = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/owner-profiles/{$owner->id}")->assertOk()->json('data');

    expect($data['account']['name'])->toBe('Profilia Santos')
        ->and($data['account']['status'])->toBe('active')
        ->and($data['summary'])->toMatchArray([
            'businesses' => 1, 'active_permits' => 2, 'filings' => 1, 'needs_attention' => 0,
        ])
        ->and($data['summary'])->not->toHaveKey('total_paid')
        ->and($data['payments'])->toHaveCount(2)
        ->and($data['businesses'][0]['id'])->toBe($business->id);

    $permits = collect($data['businesses'][0]['permits']);
    // The Mayor's Permit leads, and each term is the one on file.
    expect($permits->first()['type_code'])->toBe(PermitType::OUTCOME_CODE)
        ->and($permits->firstWhere('id', $sanitary->id)['valid_until'])->toBe('2027-09-22')
        ->and(collect($data['history'])->last()['label'])->toBe('Account created');
});

it('keeps an office to the owners on its own caseload', function () {
    ['owner' => $owner, 'app' => $app] = profileOwner();

    // Not routed to City Health: not on its list, and the profile is not found.
    expect(ownerListIds('sanitary@biztrack.local'))->not->toContain($owner->id);
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/owner-profiles/{$owner->id}")->assertNotFound();

    assignOffice($app->id, 'CHO');

    expect(ownerListIds('sanitary@biztrack.local'))->toContain($owner->id);
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/owner-profiles/{$owner->id}")->assertOk()
        ->assertJsonPath('data.summary.filings', 1);
});

it('gives each seat only the account facts it needs, and an office only its own fee', function () {
    ['owner' => $owner, 'app' => $app] = profileOwner();
    assignOffice($app->id, 'CHO');

    $read = fn (string $email) => test()->withHeaders(authAs($email))
        ->getJson("/api/v1/owner-profiles/{$owner->id}")->assertOk()->json('data');

    // No seat is sent the home address.
    foreach (['admin@biztrack.local', 'bplo@biztrack.local', 'sanitary@biztrack.local'] as $email) {
        expect($read($email)['account'])->not->toHaveKey('home_address');
    }

    $office = $read('sanitary@biztrack.local');
    expect($office['account'])->toHaveKeys(['name', 'gender', 'status'])
        ->and($office['account'])->not->toHaveKeys(['email', 'mobile_number', 'last_login_at', 'is_active', 'blacklist_reason'])
        // Its own share of the bill, not the owner's payments.
        ->and($office['payments'])->toBeNull()
        ->and($office['fees'])->toHaveCount(1)
        ->and($office['fees'][0])->toMatchArray(['amount' => 300, 'paid' => true])
        ->and($office['can_message'])->toBeTrue();

    $bplo = $read('bplo@biztrack.local');
    expect($bplo['account'])->toHaveKeys(['email', 'mobile_number', 'created_at'])
        ->and($bplo['account'])->not->toHaveKeys(['last_login_at', 'is_active', 'blacklist_reason'])
        ->and($bplo['payments'])->toHaveCount(2)
        ->and($bplo['fees'])->toBeNull()
        ->and($bplo['can_message'])->toBeTrue();

    $admin = $read('admin@biztrack.local');
    expect($admin['account'])->toHaveKeys(['email', 'last_login_at', 'is_active', 'blacklist_reason'])
        // The super admin holds no conversation with owners.
        ->and($admin['can_message'])->toBeFalse();
});

it('refuses the owner list to a business owner', function () {
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/owner-profiles')->assertForbidden();
});

it('tells each permit status change once, and never names a person for a term running out', function () {
    ['owner' => $owner, 'sanitary' => $sanitary, 'mayors' => $mayors] = profileOwner();

    // Whoever's session the scan happened to run in is not who expired it.
    Auth::login($owner);
    Audit::log('permit.expired', $mayors, ['from' => 'active', 'to' => 'expired']);
    Auth::login(User::where('email', 'sanitary@biztrack.local')->firstOrFail());
    Audit::log('permit.status_changed', $sanitary, ['from' => 'active', 'to' => 'rejected', 'reason' => 'Failed inspection']);
    Auth::logout();

    $history = collect(test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/owner-profiles/{$owner->id}")->assertOk()->json('data.history'));

    $expired = $history->firstWhere('to', 'expired');
    $rejected = $history->firstWhere('to', 'rejected');

    expect($expired['by'])->toBeNull()
        ->and($rejected['reason'])->toBe('Failed inspection')
        ->and($rejected['by'])->not->toBeNull()
        // Each entry says what it is about and what moved.
        ->and($rejected)->toMatchArray([
            'office_code' => 'CHO',
            'permit_type' => 'Sanitary Permit',
            'permit_number' => $sanitary->permit_number,
            'from_label' => 'Active',
            'by_office' => 'CHO',
        ])
        ->and($rejected['business_name'])->not->toBeNull();
});

it('gives a clearance office its own certificate\'s history only, and BPLO all of it', function () {
    ['owner' => $owner, 'app' => $app, 'sanitary' => $sanitary, 'mayors' => $mayors] = profileOwner();
    assignOffice($app->id, 'CHO');

    Auth::login(User::where('email', 'bplo@biztrack.local')->firstOrFail());
    Audit::log('permit.status_changed', $mayors, ['from' => 'active', 'to' => 'suspended', 'reason' => 'Unpaid tax']);
    Auth::logout();

    $read = fn (string $email) => collect(test()->withHeaders(authAs($email))
        ->getJson("/api/v1/owner-profiles/{$owner->id}")->assertOk()->json('data.history'));

    $cho = $read('sanitary@biztrack.local');
    // The Sanitary Permit's issue and nothing else: no Mayor's Permit, no account.
    expect($cho->pluck('office_code')->unique()->values()->all())->toBe(['CHO'])
        ->and($cho->pluck('permit_number')->unique()->values()->all())->toBe([$sanitary->permit_number]);

    $bplo = $read('bplo@biztrack.local');
    expect($bplo->pluck('office_code')->filter()->unique()->sort()->values()->all())->toBe(['BPLO', 'CHO'])
        ->and($bplo->firstWhere('reason', 'Unpaid tax')['permit_number'])->toBe($mayors->permit_number)
        ->and($bplo->last()['label'])->toBe('Account created');
});

it('carries the owner account on each Permits row, for the Owner column link', function () {
    ['owner' => $owner, 'mayors' => $mayors] = profileOwner();

    $row = collect(test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?detail=1&per_page=100&q='.urlencode($mayors->permit_number))->assertOk()->json('data'))
        ->firstWhere('id', $mayors->id);

    expect($row['business']['owner_user_id'])->toBe($owner->id);
});
