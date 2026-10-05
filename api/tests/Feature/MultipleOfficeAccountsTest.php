<?php

use App\Enums\ClearanceStatus;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
 *  - COMMUNICATE ONLINE: a general enquiry to the City Health Office reaches
 *    every City Health account. A filing's conversation, since the request of
 *    6 October 2026, reaches the account HOLDING City Health's case on it and
 *    nobody else in the office — and passes whole to the next holder, so a
 *    reply from whichever account holds it is still the office speaking.
 *    (It reached every account until that date.)
 *  - CREATE OTHER REQUIREMENTS: a requirement raised by one officer is the
 *    OFFICE's requirement — a colleague sees it, and rules on it once the case
 *    is handed to them — while staying invisible to every other office. (Any
 *    colleague could rule on it until 6 October 2026.)
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
            'password' => 'Biztrack-Test1!',
            'role' => $role,
            'department_id' => Department::where('code', $code)->value('id'),
        ])->assertCreated();

    return User::where('email', $email)->firstOrFail();
}

/**
 * A submitted filing routed to the named offices.
 *
 * The rows are written directly. submit() routes BPLO alone, and the five
 * clearance offices arrive one at a time as the applicant opens each permit
 * after paying — driving all of that would make these tests about the workflow
 * rather than about an office holding more than one account.
 */
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
        // RA 10173 consent is the first gate submit() runs.
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    foreach ($codes as $code) {
        $departmentId = Department::where('code', $code)->value('id');
        ApplicationAssignment::firstOrCreate([
            'application_id' => $appId,
            'department_id' => $departmentId,
        ]);

        /*
         * And the office's own permit is with it, which is what being
         * routed means: `startClearance` moves the permit and routes the
         * office in one transaction, so an assignment never arrives ahead
         * of the paperwork.
         *
         * Without this the row is the shape the OLD model left behind —
         * an open assignment over a permit nobody submitted — which the
         * queue has hidden since 30 September 2026, on the client's
         * report of exactly such a row in CPDO's queue. These cases are
         * about whether two accounts share one office's work, so the
         * submitted state is scenery; it was simply wrong scenery.
         */
        DB::table('application_permit_types')
            ->where('application_id', $appId)
            ->whereIn(
                'permit_type_id',
                PermitType::where('issuing_department_id', $departmentId)
                    ->where('code', '!=', PermitType::OUTCOME_CODE)
                    ->pluck('id'),
            )
            ->update(['status' => ClearanceStatus::ForApproval->value]);
    }

    return $appId;
}

/*
 * ── Two accounts, one case: since 6 October 2026 the holder works it ───────
 *
 * The three cases that follow said, until that day, that every account in an
 * office read the office's mail on a filing, answered it, and ruled on its
 * requirements. The request of 6 October 2026 changed that for every office
 * but BPLO: "The officer must click 'Assign to Me' before they can access and
 * process the application. Once assigned, other officers can only view the
 * application", and a filing's conversation is its holder's alone — "only the
 * newly assigned officer can access the conversation" once it changes hands.
 *
 * What survives of "an office is a place, not a person" is what the cases now
 * pin: the office's GENERAL enquiry still reaches every account in it; the
 * conversation and the requirements belong to the office's CASE, so they pass
 * whole to whichever account holds it next; and the applicant still sees one
 * office, never two people. BPLO, which kept the older rule, is pinned in
 * ClaimBeforeWorkTest and OfficerTenureTest.
 */
it('gives a filing’s conversation to the account holding the case, and the office’s general enquiry to every account', function () {
    extraOfficer('CHO', 'sanitary_officer', 'cho.second@biztrack.local');
    $appId = filingRoutedTo(['CHO', 'BFP'], 'DTI-93001');
    $cho = Department::where('code', 'CHO')->value('id');

    /*
     * sanitary@ takes the case and opens the conversation; the owner answers
     * (an owner may not start one with an office nobody there holds the filing
     * for, checklist 2026-09-27 apply item 23 — it is held here, but the
     * office speaking first is the order the old case had).
     */
    claimAs('sanitary@biztrack.local', $appId);
    test()->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Please send the water result.'])
        ->assertCreated();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Attaching the water potability result.',
            'department_id' => $cho,
        ])->assertCreated();

    // The holder reads it.
    $holderBodies = collect(test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data'))
        ->pluck('body');
    expect($holderBodies)->toContain('Attaching the water potability result.');

    // The colleague does not: the case is not theirs. Refused outright rather
    // than shown an empty thread, and the filing is not in their inbox.
    test()->withHeaders(authAs('cho.second@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")
        ->assertForbidden();
    $colleagueRows = collect(test()->getJson('/api/v1/message-threads')->assertOk()->json('data'));
    expect($colleagueRows->where('kind', '!=', 'general')->pluck('application.id'))->not->toContain($appId);

    // The fire office, routed to the same filing and holding its own case
    // there, reads none of City Health's.
    claimAs('fire@biztrack.local', $appId);
    $fireBodies = collect(test()->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data'))
        ->pluck('body');
    expect($fireBodies)->not->toContain('Attaching the water potability result.');

    /*
     * A general enquiry has no filing and so no holder: both City Health
     * accounts read it, which is the part of "the same inbox" the request did
     * not touch.
     */
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/general-messages', ['department_id' => $cho, 'body' => 'A question for City Health.'])
        ->assertCreated();
    foreach (['sanitary@biztrack.local', 'cho.second@biztrack.local'] as $email) {
        $enquiry = collect(test()->withHeaders(authAs($email))
            ->getJson('/api/v1/message-threads')->assertOk()->json('data'))
            ->where('kind', 'general')
            ->firstWhere('last_message.body', 'A question for City Health.');

        expect($enquiry)->not->toBeNull();
    }
});

it('lets only the account holding the case answer for the office, and the account that takes it over after', function () {
    extraOfficer('CHO', 'sanitary_officer', 'cho.second@biztrack.local');
    $appId = filingRoutedTo(['CHO'], 'DTI-93002');
    $cho = Department::where('code', 'CHO')->value('id');
    $assignmentId = claimAs('sanitary@biztrack.local', $appId);

    // The holder opens the conversation and the owner answers.
    test()->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Any questions on the sanitary permit?'])
        ->assertCreated();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Question.', 'department_id' => $cho])
        ->assertCreated();

    // The colleague may not answer while the case is with somebody else.
    test()->withHeaders(authAs('cho.second@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Not mine to answer.'])
        ->assertForbidden();

    // Handed over: the holder puts it back, the colleague takes it.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignmentId}/release")->assertOk();
    claimAs('cho.second@biztrack.local', $appId);

    // Now the colleague answers as the office, with the whole conversation in
    // front of them — the stretch before they held it included.
    test()->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'City Health here — send the lab result.'])
        ->assertCreated();
    $asSecond = collect(test()->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data'))
        ->pluck('body');
    expect($asSecond)
        ->toContain('Any questions on the sanitary permit?')
        ->toContain('Question.')
        ->toContain('City Health here — send the lab result.');

    // The applicant still sees one office conversation, not two people.
    $thread = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages?department_id={$cho}")->assertOk()->json('data'));
    expect($thread->pluck('body'))
        ->toContain('Question.')
        ->toContain('City Health here — send the lab result.');

    // And the officer who handed it on keeps nothing of it.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")
        ->assertForbidden();
});

it('keeps a requirement with the office across a handover, for whichever account holds the case to rule on', function () {
    extraOfficer('CHO', 'sanitary_officer', 'cho.second@biztrack.local');
    $appId = filingRoutedTo(['CHO', 'BFP'], 'DTI-93003');
    $assignmentId = claimAs('sanitary@biztrack.local', $appId);

    $requestId = test()->postJson("/api/v1/applications/{$appId}/requests", [
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

    // While sanitary@ holds the case, the colleague may only read it.
    test()->withHeaders(authAs('cho.second@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertForbidden();

    /*
     * Whoever raised a requirement may be on leave by the time it is answered.
     * Before 6 October any colleague could rule on it; now the case is handed
     * over and the requirement goes with it — it is the office's, not the
     * person's, so the new holder rules on what the old one raised.
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignmentId}/release")->assertOk();
    claimAs('cho.second@biztrack.local', $appId);
    test()->postJson("/api/v1/requests/{$requestId}/close", [
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
    // City Health's officer holds its case, so may raise (6 October 2026).
    claimAs('sanitary@biztrack.local', $appId);

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
