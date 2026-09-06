<?php

use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * Nothing answers 5xx to bad input.
 *
 * A 422 is an answer — it names the field and the applicant can fix it. A 500 is
 * a crash where a rule should have been, and it says nothing useful to anyone.
 * These are the cases a sweep of every write endpoint actually turned up, not a
 * theoretical list.
 */

it('rejects an array in a scalar field on the business form instead of crashing', function () {
    /*
     * The TIN is normalised before validation runs, so applicants may type the
     * separators they are used to. That tidy-up cast the input to string, which
     * on `tin[]=x` is a TypeError against a `string` parameter — so the endpoint
     * answered 500 from inside the pre-validation step, before the rule that
     * would have said "enter a valid TIN" ever ran.
     */
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', [
            'name' => ['an', 'array'],
            'registration_type' => ['an', 'array'],
            'registration_number' => ['an', 'array'],
            'tin' => ['an', 'array'],
            'address' => 'not an array',
            'lines' => 'not an array',
        ])
        ->assertStatus(422);
});

it('ignores a nested array in the queue filter instead of crashing', function () {
    // `?application_status[][]=x` — casting the inner array to string is a
    // TypeError, and the queue answered 500 to a malformed query string.
    //
    // As BPLO: the queue is an officer's screen, and the super admin lost
    // `application.review` when the client took Track off that role, so an
    // admin session would be turned away at the gate and never reach the parser
    // this is about.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/assignments?application_status[][]=submitted')
        ->assertOk();
});

it('caps the opaque fee profile the way it caps the opaque office form', function () {
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Payload Bakery',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-77001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Payload St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 1000]],
    ])->assertCreated()->json('data.id');

    $permitTypeIds = PermitType::where('code', 'BUSINESS')->pluck('id')->all();

    // fee_profile is stored verbatim as JSON, so keys the calculator never reads
    // were kept unchallenged at any size.
    test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => $permitTypeIds,
        'fee_profile' => ['junk' => str_repeat('x', 40000)],
    ])->assertStatus(422);

    // And an absurd number of fee lines is a validation failure, not a slow write.
    test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => $permitTypeIds,
        'fee_profile' => ['lines' => array_fill(0, 201, ['category' => 'Retail'])],
    ])->assertStatus(422);

    // A normal profile still goes through.
    test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => $permitTypeIds,
        'fee_profile' => ['gross_sales' => 500000, 'employees' => 4],
    ])->assertCreated();
});

it('caps a fee adjustment rather than overflowing the money columns', function () {
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/applications/1/fee/adjust', [
            'line_items' => [['label' => 'Absurd', 'amount' => '1e30']],
            'total_amount' => '1e30',
        ])
        ->assertStatus(422);
});

it('answers 422, not 500, to hostile query strings on every list', function () {
    /*
     * The account has to be one that may actually READ each list. This asserts
     * "not a 500", and a 403 clears that bar without the query string ever
     * reaching the code being tested — so leaving the super admin on
     * /assignments, /inspections and /requests after it lost Track, Inspections
     * and Other Requirements would leave three of these seven passing vacuously.
     */
    $lists = [
        ['/api/v1/applications', 'admin@biztrack.local'],
        ['/api/v1/assignments', 'bplo@biztrack.local'],
        ['/api/v1/inspections', 'sanitary@biztrack.local'],
        ['/api/v1/requests', 'bplo@biztrack.local'],
        ['/api/v1/admin/users', 'admin@biztrack.local'],
        ['/api/v1/admin/businesses', 'admin@biztrack.local'],
        ['/api/v1/admin/audit-logs', 'admin@biztrack.local'],
    ];

    foreach ($lists as [$uri, $email]) {
        foreach (['?status[]=x', '?q[]=x', '?per_page[]=1', '?page[]=1', '?action[]=x', '?role[]=x'] as $query) {
            $status = test()->withHeaders(authAs($email))->getJson($uri.$query)->getStatusCode();

            expect($status)->toBeLessThan(500, "{$uri}{$query} answered {$status}")
                ->and($status)->not->toBe(403, "{$uri}: {$email} may not read this list, so nothing was tested");
        }
    }
});
