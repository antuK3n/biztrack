<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * The separation rule, stated as tests rather than trusted from comments.
 *
 * Every combination of FILING and OFFICE is its own conversation, and a
 * general enquiry — the one an owner can have before they file anything — is
 * separate from all of them. A message must reach the office it was addressed
 * to and no other, in either direction.
 *
 * These are written from the outside, through the API, because that is where
 * the boundary has failed before: checklist items 56 and 111 were both cases
 * of one door enforcing the rule and another not.
 */

/** A filed application owned by owner@biztrack.local, with a tracking id. */
function separationApplication(string $businessName, string $registrationNumber): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $businessName,
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Separation St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return [$appId, Application::find($appId)->tracking_id];
}

/** Put an office on a filing so the owner is allowed to address it. */
function assignOffice(int $applicationId, string $departmentCode): int
{
    $department = Department::where('code', $departmentCode)->firstOrFail();

    ApplicationAssignment::firstOrCreate([
        'application_id' => $applicationId,
        'department_id' => $department->id,
    ]);

    return $department->id;
}

it('keeps a message to the health office out of an unrelated office inbox', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70001');
    $choId = assignOffice($appId, 'CHO');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Question for the health office only.',
        'department_id' => $choId,
    ])->assertCreated();

    // The health office reads it.
    $cho = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data');
    expect(collect($cho)->pluck('body'))->toContain('Question for the health office only.');

    /*
     * An unrelated office does not — not the row, and not the message behind
     * it. Item 111 is exactly this: hiding the row while still serving the
     * message is not hiding it.
     */
    $fire = test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->json('data') ?? [];

    expect(collect($fire)->pluck('body'))->not->toContain('Question for the health office only.');
});

/*
 * The one office that is NOT separated from the others, stated as a rule so
 * that changing it is a decision and not an accident.
 *
 * BPLO holds `application.view_any_office`, so readsThreadOf answers true for
 * every office and BPLO reads a conversation addressed to the health office.
 * That is deliberate and predates this file: BPLO coordinates every filing and
 * issues the mayor's permit off the other offices' clearances, and the
 * department_id migration attributed 520 unaddressed historical threads to it
 * on exactly that reasoning — it "already reads the entire register".
 *
 * If the rule should instead be that BPLO sees only what is addressed to BPLO,
 * this test is the one to change, and narrowing the permission is the change —
 * not a filter added at one more door.
 */
it('lets BPLO read another office conversation, which is the documented exception', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70008');
    $choId = assignOffice($appId, 'CHO');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Question for the health office only.',
        'department_id' => $choId,
    ])->assertCreated();

    $bplo = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data');

    expect(collect($bplo)->pluck('body'))->toContain('Question for the health office only.');
});

it('keeps an office reply out of the other offices on the same filing', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70002');
    $choId = assignOffice($appId, 'CHO');
    assignOffice($appId, 'BFP');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Health question.',
        'department_id' => $choId,
    ])->assertCreated();

    // The office answers. Section 5: an office initiating or replying is bound
    // by the same rule as the applicant.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Bring the sanitary permit.'])
        ->assertCreated();

    $fire = test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->json('data') ?? [];

    expect(collect($fire)->pluck('body'))
        ->not->toContain('Bring the sanitary permit.')
        ->not->toContain('Health question.');

    // The owner, who is a party to it, sees both turns.
    $owner = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages?department_id={$choId}")
        ->assertOk()->json('data');

    expect(collect($owner)->pluck('body'))
        ->toContain('Health question.')
        ->toContain('Bring the sanitary permit.');
});

it('never mixes the conversations of two different filings', function () {
    [$firstId, $firstTracking] = separationApplication('ABC Store', 'DTI-70003');
    [$secondId, $secondTracking] = separationApplication('XYZ Cafe', 'DTI-70004');

    expect($firstTracking)->not->toBe($secondTracking);

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$firstId}/messages", ['body' => 'About ABC Store.'])
        ->assertCreated();
    $this->postJson("/api/v1/applications/{$secondId}/messages", ['body' => 'About XYZ Cafe.'])
        ->assertCreated();

    $first = $this->getJson("/api/v1/applications/{$firstId}/messages")->assertOk()->json('data');
    $second = $this->getJson("/api/v1/applications/{$secondId}/messages")->assertOk()->json('data');

    expect(collect($first)->pluck('body'))->toContain('About ABC Store.')->not->toContain('About XYZ Cafe.');
    expect(collect($second)->pluck('body'))->toContain('About XYZ Cafe.')->not->toContain('About ABC Store.');
});

it('names the business and the filing on each inbox row so two are told apart', function () {
    [$firstId, $firstTracking] = separationApplication('ABC Store', 'DTI-70005');
    [$secondId, $secondTracking] = separationApplication('XYZ Cafe', 'DTI-70006');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$firstId}/messages", ['body' => 'One.'])->assertCreated();
    $this->postJson("/api/v1/applications/{$secondId}/messages", ['body' => 'Two.'])->assertCreated();

    $rows = collect($this->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'));

    $first = $rows->firstWhere('application_id', $firstId);
    $second = $rows->firstWhere('application_id', $secondId);

    // Business name AND tracking id on both, so the two rows are distinguishable
    // even when the business name is the same.
    expect($first['business_name'])->toBe('ABC Store')
        ->and($first['tracking_id'])->toBe($firstTracking)
        ->and($second['business_name'])->toBe('XYZ Cafe')
        ->and($second['tracking_id'])->toBe($secondTracking);
});

it('keeps the general enquiry apart from the filing conversations', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70007');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'About my filing.'])
        ->assertCreated();
    $this->postJson('/api/v1/general-messages', ['body' => 'Can I change my email?'])
        ->assertCreated();

    // Neither conversation carries the other's turns.
    $filing = $this->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data');
    $general = $this->getJson('/api/v1/general-messages')->assertOk()->json('data');

    expect(collect($filing)->pluck('body'))
        ->toContain('About my filing.')
        ->not->toContain('Can I change my email?');
    expect(collect($general)->pluck('body'))
        ->toContain('Can I change my email?')
        ->not->toContain('About my filing.');

    // And they are two rows in the inbox, one of them carrying no filing.
    $rows = collect($this->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'));

    expect($rows->firstWhere('kind', 'general')['application_id'])->toBeNull()
        ->and($rows->firstWhere('application_id', $appId)['kind'])->toBe('application');
});
