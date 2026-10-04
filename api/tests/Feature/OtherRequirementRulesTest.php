<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\OfficerRequest;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\OtherRequirementRules;

/*
 * The Other Requirements a business's nature calls for.
 *
 * Client, 5 October 2026: *"I am planning to have rules that will tell which
 * Other Requirements are required to have for a business. Does the revenue
 * code … state something about this?"* It does — Articles T, U, 4D and
 * Sec. 3X — and `OtherRequirementRules` is that statement as a table.
 * These tests pin its two readers: the preview the wizard shows before the
 * press, and the system requirements raised by the press itself.
 */

/** A New filing for every permit, with the given fee-profile flags, submitted. */
function filingDeclaring(array $flags): Application
{
    $owner = authAs('owner@biztrack.local');
    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Rules Trading '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'address' => ['line1' => '5 Rule Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$appId}", ['fee_profile' => ['flags' => $flags]])
        ->assertOk();

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId);
}

function ruleRequestsOn(Application $app)
{
    return OfficerRequest::where('application_id', $app->id)
        ->where('system_key', 'like', OtherRequirementRules::KEY_PREFIX.'%')
        ->orderBy('id')
        ->get();
}

it('raises a system requirement for each rule the declared flags turn on', function () {
    $app = filingDeclaring(['sells_liquor', 'employees_need_health_certificates']);

    $raised = ruleRequestsOn($app);
    expect($raised->pluck('system_key')->all())->toBe(['rule.liquor_permit', 'rule.health_certificates']);

    $liquor = $raised->firstWhere('system_key', 'rule.liquor_permit');
    expect($liquor->requested_by_user_id)->toBeNull()
        ->and($liquor->request_type)->toBe('message')
        ->and($liquor->title)->toBe('Liquor Permit')
        ->and($liquor->department_id)->toBe(Department::where('code', 'BPLO')->value('id'))
        ->and($liquor->description)->toContain('Sec. 3T.04');

    // The Health Certificates are the City Health Office's to see, and a file.
    $health = $raised->firstWhere('system_key', 'rule.health_certificates');
    expect($health->request_type)->toBe('document')
        ->and($health->department_id)->toBe(Department::where('code', 'CHO')->value('id'));

    // Each is on the record as the system's doing, naming the article behind it.
    $audits = AuditLog::where('action', 'request.raised_by_system')
        ->where('changes->application_id', $app->id)
        ->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('changes.rule')->all())->toContain('Revenue Code Art. T, Sec. 3T.01');
});

it('raises nothing for a business that declared none of them', function () {
    $app = filingDeclaring(['has_signage']);

    expect(ruleRequestsOn($app))->toHaveCount(0);
});

it('informs about a tobacco permit but asks for nothing after submission', function () {
    /*
     * Art. U wants a Mayor's Permit and a fee, and the fee is already on the
     * assessment. A requirement with nothing to attach and nothing to answer
     * would sit under Other Requirements forever, so the row is told before
     * the press and raises no request after it.
     */
    $profile = ['flags' => ['sells_tobacco_retail'], 'categories' => []];

    expect(OtherRequirementRules::preview($profile))->toHaveCount(1)
        ->and(OtherRequirementRules::preview($profile)[0]['asks'])->toBeFalse()
        ->and(OtherRequirementRules::raisable($profile))->toBe([]);

    expect(ruleRequestsOn(filingDeclaring(['sells_tobacco_retail'])))->toHaveCount(0);
});

it('does not raise a rule twice on the same filing', function () {
    /*
     * firstOrCreate on (application, system_key): a filing whose rule row is
     * already there — raised earlier, or by hand under the same key — gets no
     * second copy however many times the submit path runs over it.
     */
    $owner = authAs('owner@biztrack.local');
    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Twice Trading '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'address' => ['line1' => '5 Rule Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');
    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');
    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$appId}", ['fee_profile' => ['flags' => ['sells_liquor']]])
        ->assertOk();

    OfficerRequest::create([
        'application_id' => $appId,
        'requested_by_user_id' => null,
        'department_id' => Department::where('code', 'BPLO')->value('id'),
        'title' => 'Liquor Permit',
        'description' => 'raised earlier',
        'request_type' => 'message',
        'system_key' => 'rule.liquor_permit',
        'status' => 'pending',
    ]);

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    expect(ruleRequestsOn(Application::findOrFail($appId)))->toHaveCount(1);
});

it('hands the wizard the same list beside the fee estimate', function () {
    $owner = authAs('owner@biztrack.local');
    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Preview Trading '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'address' => ['line1' => '5 Rule Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');
    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    $preview = test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/fee-preview", [
            'fee_profile' => ['flags' => ['sells_liquor', 'sells_tobacco_retail', 'is_ambulant_vendor']],
        ])
        ->assertOk()
        ->json('data.other_requirements');

    expect(array_column($preview, 'key'))->toBe(['liquor_permit', 'tobacco_permit', 'ambulant_vendor'])
        ->and(array_column($preview, 'asks'))->toBe([true, false, false])
        ->and($preview[0])->toHaveKeys(['title', 'article', 'summary']);

    // And nothing declared is nothing listed — the key is always present.
    $none = test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/fee-preview", ['fee_profile' => ['flags' => []]])
        ->assertOk()
        ->json('data.other_requirements');
    expect($none)->toBe([]);
});

it('names only departments that exist and keys that are its own', function () {
    $codes = Department::pluck('code')->all();
    foreach (OtherRequirementRules::all() as $row) {
        expect(in_array($row['department'], $codes, true))->toBeTrue(
            "rule '{$row['key']}' is for department '{$row['department']}', which is not seeded"
        );
        expect($row['request_type'] === null)->toBe($row['description'] === null,
            "rule '{$row['key']}': a row asks for something exactly when it has a description to ask with"
        );
    }
    expect(OtherRequirementRules::systemKey('liquor_permit'))->toBe('rule.liquor_permit');
});

it('no longer carries the quantity-priced permits the client dropped', function () {
    /*
     * Client, 5 October 2026, on the flammables, machinery and lumberyard
     * rows: *"safe to not include this for less complexity."* Each was priced
     * by a quantity the applicant would have had to reply with. A profile
     * still carrying one of their old flags gets nothing from them.
     */
    $keys = array_column(OtherRequirementRules::all(), 'key');
    expect($keys)->toBe(['liquor_permit', 'tobacco_permit', 'health_certificates', 'ambulant_vendor']);

    $profile = ['flags' => ['stores_flammables', 'operates_machinery', 'is_lumberyard'], 'categories' => []];
    expect(OtherRequirementRules::matching($profile))->toBe([]);
});
