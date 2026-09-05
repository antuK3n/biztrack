<?php

use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * An office is a place, not a person.
 *
 * The register shipped with exactly one account per office, so every
 * office-scoped feature was only ever exercised one-deep: an office's mail, its
 * requirements and its queue could all have been keyed to the individual rather
 * than the department and nothing would have looked wrong. Now that an office
 * may hold several accounts — the client's requirement — that assumption has to
 * be true rather than merely untested.
 *
 * What this file pins, for each named feature:
 *
 *  - COMMUNICATE ONLINE: a message sent to the City Health Office reaches every
 *    City Health account, not only whoever happened to be named on the thread,
 *    and a reply from either of them is the office speaking.
 *  - CREATE OTHER REQUIREMENTS: a requirement raised by one officer is the
 *    OFFICE's requirement — a colleague sees it and can rule on it — while
 *    staying invisible to every other office.
 *  - The office boundary itself does not soften as accounts are added.
 */

/** A second (or third) account inside an existing office. */
function extraOfficer(string $code, string $role, string $email, string $first = 'Extra'): User
{
    // Created through the endpoint the super admin actually uses, not by a
    // direct insert — the point is that this route can staff an office twice.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', [
            'first_name' => $first,
            'last_name' => strtoupper($code),
            'gender' => 'F',
            'email' => $email,
            'mobile_number' => '09171234567',
            'password' => 'biztrack1',
            'role' => $role,
            'department_id' => Department::where('code', $code)->value('id'),
        ])->assertCreated();

    return User::where('email', $email)->firstOrFail();
}

/** A submitted filing routed to the named offices. */
function filingRoutedTo(array $codes, string $registrationNumber): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Multi Office Cafe',
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Office Row', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    foreach ($codes as $code) {
        ApplicationAssignment::firstOrCreate([
            'application_id' => $appId,
            'department_id' => Department::where('code', $code)->value('id'),
        ]);
    }

    return $appId;
}

it('gives every account in an office the same inbox', function () {
    extraOfficer('CHO', 'sanitary_officer', 'cho.second@biztrack.local');
    $appId = filingRoutedTo(['CHO', 'BFP'], 'DTI-93001');

    // The applicant writes to the City Health Office.
    $cho = Department::where('code', 'CHO')->value('id');
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Attaching the water potability result.',
            'department_id' => $cho,
        ])->assertCreated();

    /*
     * Both City Health accounts read it. Mail addressed to an office that only
     * its longest-serving account could open would make every handover a
     * support ticket.
     */
    foreach (['sanitary@biztrack.local', 'cho.second@biztrack.local'] as $email) {
        $bodies = collect(test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data'))
            ->pluck('body');

        expect($bodies)->toContain('Attaching the water potability result.');
    }

    // The fire office, routed to the same filing, reads none of it.
    $fireBodies = collect(test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data'))
        ->pluck('body');
    expect($fireBodies)->not->toContain('Attaching the water potability result.');
});

it('lets either account in an office answer as that office', function () {
    extraOfficer('CHO', 'sanitary_officer', 'cho.second@biztrack.local');
    $appId = filingRoutedTo(['CHO'], 'DTI-93002');
    $cho = Department::where('code', 'CHO')->value('id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Question.', 'department_id' => $cho])
        ->assertCreated();

    // The colleague replies — not the officer the applicant happened to reach.
    test()->withHeaders(authAs('cho.second@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'City Health here — send the lab result.'])
        ->assertCreated();

    // The applicant sees one office conversation, not two people.
    $thread = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages?department_id={$cho}")->assertOk()->json('data'));

    expect($thread->pluck('body'))
        ->toContain('Question.')
        ->toContain('City Health here — send the lab result.');

    // And the first officer sees their colleague's reply in the same thread.
    $asFirst = collect(test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data'))->pluck('body');
    expect($asFirst)->toContain('City Health here — send the lab result.');
});

it('makes a requirement the office’s, not the individual officer’s', function () {
    extraOfficer('CHO', 'sanitary_officer', 'cho.second@biztrack.local');
    $appId = filingRoutedTo(['CHO', 'BFP'], 'DTI-93003');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'request_type' => 'document',
            'title' => 'Water potability result',
            'description' => 'Please upload the latest laboratory result.',
        ])->assertCreated()->json('data.id');

    // The colleague sees it in their list…
    $colleagueList = collect(test()->withHeaders(authAs('cho.second@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))->pluck('id');
    expect($colleagueList)->toContain($requestId);

    // …and the fire office does not, though it is routed to the same filing.
    $fireList = collect(test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))->pluck('id');
    expect($fireList)->not->toContain($requestId);

    // The applicant answers.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/respond", ['body' => 'Attached.'])
        ->assertOk();

    /*
     * And the colleague can rule on it. Whoever raised a requirement may be on
     * leave by the time it is answered; if only they could close it, the filing
     * would sit blocked on one person's absence.
     */
    test()->withHeaders(authAs('cho.second@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", [
            'outcome' => 'needs_resubmission',
            'remarks' => 'The result is dated last year — send the current one.',
        ])->assertOk();

    $seen = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $requestId);

    expect($seen['status'])->toBe('needs_resubmission')
        ->and($seen['remarks'])->toBe('The result is dated last year — send the current one.')
        // Still stamped as City Health's, whichever of its accounts acted.
        ->and($seen['from_office']['code'])->toBe('CHO');
});

it('does not let another office rule on a requirement, however many accounts it has', function () {
    extraOfficer('BFP', 'fire_inspector', 'bfp.second@biztrack.local');
    $appId = filingRoutedTo(['CHO', 'BFP'], 'DTI-93004');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'request_type' => 'document', 'title' => 'Water potability result',
        ])->assertCreated()->json('data.id');

    // Adding accounts to an office widens that office, not the boundary.
    foreach (['fire@biztrack.local', 'bfp.second@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))
            ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
            ->assertForbidden();
    }
});

it('routes a filing to the office, so every account in it sees the queue', function () {
    extraOfficer('CHO', 'sanitary_officer', 'cho.second@biztrack.local');
    $appId = filingRoutedTo(['CHO'], 'DTI-93005');

    /*
     * The review queue is scoped by department, so a filing routed to City
     * Health is work for City Health — not for the one account that happened to
     * exist when it arrived. An unassigned case is exactly what both accounts
     * should be able to pick up.
     */
    foreach (['sanitary@biztrack.local', 'cho.second@biztrack.local'] as $email) {
        $queue = collect(test()->withHeaders(authAs($email))
            ->getJson('/api/v1/assignments?per_page=200')->assertOk()->json('data'))
            ->pluck('application.id');

        // toContain takes needles, not a message — a "message" here would be
        // asserted as a second value that is never present.
        expect($queue)->toContain($appId);
    }
});
