<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\ClearanceStanding;

/*
 * What BPLO can see at Final Approval about a renewal it is NOT renewing.
 *
 * ── The gap this closes ─────────────────────────────────────────────────────
 *
 * Client on the purpose of the stage: *"the only purpose of Final Approval was
 * to check if all clearance permits are done."* Client on renewals,
 * 18 September 2026: an other permit *"can be reused for a business permit
 * renewal as long as this other permit is still valid/not expired"*, so it is
 * not ticked and not attached.
 *
 * Put together, BPLO's Final Approval on a January renewal of the business
 * permit alone had exactly one permit type on the filing and no way at all to
 * see the five certificates it was meant to be checking. Measured before this
 * was built: such a filing carries 1 pivot row.
 *
 * So the standing is read off the BUSINESS. These tests pin the three things
 * that make it worth reading — that it finds a certificate no pivot row points
 * at, that it distinguishes the kinds of gap, and that it does not leak five
 * offices' certificates to one office.
 */

/** A business holding the given code => valid_until pairs. `null` = no permit. */
function standingBusiness(array $validUntil): Business
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Standing Test Store '.uniqid(),
        'registration_type' => 'DTI',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '12 Standing St',
        'status' => 'active',
    ]);

    // Every certificate was issued BY some filing — `permits.application_id` is
    // not nullable — so the fixture needs one even though nothing reads it.
    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    foreach ($validUntil as $code => $until) {
        if ($until === null) {
            continue;
        }
        $type = PermitType::where('code', $code)->firstOrFail();
        Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => $type->id,
            'permit_number' => 'STAND-'.$code.'-'.uniqid(),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => $until,
            'status' => 'active',
        ]);
    }

    return $business;
}

/** A renewal on that business carrying only the codes given. */
function standingRenewal(Business $business, array $codes): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'for_final_approval',
    ]);

    foreach ($codes as $code) {
        $app->permitTypes()->attach(
            PermitType::where('code', $code)->value('id'),
            ['status' => 'approved'],
        );
    }

    return $app->load('permitTypes');
}

/** The row for one clearance code, by code rather than by position. */
function standingRow(array $rows, string $code): array
{
    $found = collect($rows)->firstWhere('permit_type_code', $code);
    expect($found)->not->toBeNull();

    return $found;
}

it('finds the certificates a business holds that this filing does not carry', function () {
    $business = standingBusiness([
        'SANITARY' => now()->addMonths(8),
        'FSIC' => now()->addMonths(4),
        'ZONING' => now()->addMonths(10),
        'OCCUPANCY' => now()->addMonths(6),
        'CEC' => now()->addMonths(9),
    ]);
    // The January case exactly: the business permit alone.
    $app = standingRenewal($business, ['BUSINESS']);

    $rows = ClearanceStanding::forApplication($app);

    // All five required clearances, none of them on the filing, all found.
    expect($rows)->toHaveCount(count(PermitType::REQUIRED_CLEARANCE_CODES));
    foreach (PermitType::REQUIRED_CLEARANCE_CODES as $code) {
        $row = standingRow($rows, $code);
        expect($row['state'])->toBe('valid')
            ->and($row['on_this_filing'])->toBeFalse()
            ->and($row['permit_number'])->not->toBeNull();
    }
});

it('tells a lapsed certificate from one the business never held', function () {
    /*
     * The distinction the screen is for. Both are gaps and neither blocks, but
     * they are different conversations: the lapsed one has been inspected
     * before and the missing one never has.
     */
    $business = standingBusiness([
        'SANITARY' => now()->subMonths(2),
        'FSIC' => null,
        'ZONING' => now()->addMonths(10),
        'OCCUPANCY' => now()->addDays(20),
        'CEC' => now()->addMonths(9),
    ]);
    $app = standingRenewal($business, ['BUSINESS']);

    $rows = ClearanceStanding::forApplication($app);

    expect(standingRow($rows, 'SANITARY')['state'])->toBe('expired')
        ->and(standingRow($rows, 'FSIC')['state'])->toBe('missing')
        ->and(standingRow($rows, 'FSIC')['permit_number'])->toBeNull()
        ->and(standingRow($rows, 'ZONING')['state'])->toBe('valid')
        // Inside 60 days: valid, and worth saying so before BPLO relies on it.
        ->and(standingRow($rows, 'OCCUPANCY')['state'])->toBe('expiring');

    // The lapsed one keeps its number: "your Sanitary Permit MCB-… expired on
    // 18 Jul" is actionable, "you have no Sanitary Permit" is wrong.
    expect(standingRow($rows, 'SANITARY')['permit_number'])->not->toBeNull();
});

it('marks a clearance being renewed on this filing, rather than relied upon', function () {
    $business = standingBusiness([
        'SANITARY' => now()->subMonths(1),
        'FSIC' => now()->addMonths(4),
        'ZONING' => now()->addMonths(10),
        'OCCUPANCY' => now()->addMonths(6),
        'CEC' => now()->addMonths(9),
    ]);
    // The applicant noticed the lapsed sanitary and ticked it too.
    $app = standingRenewal($business, ['BUSINESS', 'SANITARY']);

    $rows = ClearanceStanding::forApplication($app);

    expect(standingRow($rows, 'SANITARY')['on_this_filing'])->toBeTrue()
        ->and(standingRow($rows, 'FSIC')['on_this_filing'])->toBeFalse();
});

it('prefers the certificate whose cover runs longest, not the newest row', function () {
    /*
     * A permit renewed early continues the term (§3 of the renewal doc), so a
     * business that renewed its FSIC in April while the old one ran to June
     * holds two live rows. The one that answers "is this business covered" is
     * the one running longest — ordering by `issued_at` would pick the April
     * row and report cover ending in June as ending last year.
     */
    $business = standingBusiness([
        'SANITARY' => now()->addMonths(8),
        'FSIC' => now()->addMonths(2),
        'ZONING' => now()->addMonths(10),
        'OCCUPANCY' => now()->addMonths(6),
        'CEC' => now()->addMonths(9),
    ]);

    $type = PermitType::where('code', 'FSIC')->firstOrFail();
    $priorApp = Application::where('business_id', $business->id)->firstOrFail();
    // Issued LATER, covering LONGER — the renewal of the row above.
    Permit::create([
        'application_id' => $priorApp->id,
        'business_id' => $business->id,
        'permit_type_id' => $type->id,
        'permit_number' => 'STAND-FSIC-LONGER',
        'issued_at' => now(),
        'valid_from' => now(),
        'valid_until' => now()->addMonths(14),
        'status' => 'active',
    ]);

    $app = standingRenewal($business, ['BUSINESS']);
    $row = standingRow(ClearanceStanding::forApplication($app), 'FSIC');

    expect($row['permit_number'])->toBe('STAND-FSIC-LONGER')
        ->and($row['state'])->toBe('valid');
});

it('gives the cross-office standing to BPLO and withholds it from one office', function () {
    /*
     * Five offices' certificates in one array is a cross-office view by
     * construction, and office separability is a boundary rather than a
     * preference (AGENTS.md §10). This is the fifth door of this shape on
     * ApplicationResource, so it is asserted at the endpoint rather than on the
     * predicate: the previous four were all correct predicates wired into one
     * caller and missed on another.
     */
    $business = standingBusiness([
        'SANITARY' => now()->addMonths(8),
        'FSIC' => now()->addMonths(4),
        'ZONING' => now()->addMonths(10),
        'OCCUPANCY' => now()->addMonths(6),
        'CEC' => now()->addMonths(9),
    ]);
    $app = standingRenewal($business, ['BUSINESS']);

    $bplo = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")->assertOk();
    expect($bplo->json('data.clearance_standing'))->toBeArray()
        ->and(count($bplo->json('data.clearance_standing')))->toBe(5);

    // CHO reads the filing legitimately; it does not read the other four
    // offices' certificates off the back of it.
    $cho = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}");

    if ($cho->status() === 200) {
        expect($cho->json('data.clearance_standing'))->toBeNull();
    } else {
        // Refused the filing outright is also a pass: it cannot leak what it
        // cannot reach. Asserted either way so this test does not quietly stop
        // proving anything if the scoping tightens.
        expect($cho->status())->toBe(403);
    }
});

it('reaches the review sheet, which reads the assignment door and not this one', function () {
    /*
     * The same fact through the OTHER endpoint, because that is the one the
     * screen actually uses and this codebase has been caught four times by a
     * rule wired into one of two doors: `GET /applications/{id}` resolves
     * ApplicationResource directly, while the officer's review sheet loads
     * `GET /assignments/{id}` — which overwrites its own `application` key with
     * the full resource, and only carries `clearance_standing` because its
     * eager-load list happens to include `permitTypes`.
     *
     * "Happens to" is the whole reason this test exists. Drop that relation
     * from the load list and the block disappears from BPLO's screen with
     * nothing failing anywhere.
     */
    $business = standingBusiness([
        'SANITARY' => now()->subMonths(2),
        'FSIC' => now()->addMonths(4),
        'ZONING' => now()->addMonths(10),
        'OCCUPANCY' => now()->addMonths(6),
        'CEC' => now()->addMonths(9),
    ]);
    $app = standingRenewal($business, ['BUSINESS']);

    $assignment = ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'BPLO')->value('id'),
        'status' => 'in_progress',
        'assigned_at' => now(),
    ]);

    $rows = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/assignments/{$assignment->id}")
        ->assertOk()
        ->json('data.application.clearance_standing');

    expect($rows)->toBeArray()->and($rows)->toHaveCount(5);
    expect(standingRow($rows, 'SANITARY')['state'])->toBe('expired');
});
