<?php

use App\Enums\OfficerRequestStatus;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\OfficerRequest;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * "Other Requirements", end to end: an office asks, the applicant answers, the
 * office rules, and — the part that did not exist — the applicant answers again.
 *
 * Two things were broken and both are asserted here:
 *
 *  - closing a requirement took only an outcome, so a rejection carried no
 *    reason. The `remarks` column was on the model and nothing ever wrote to
 *    it, and the resource never returned it, so the applicant saw a status
 *    change and no explanation anywhere in the app.
 *  - rejection was terminal. respond() refused a reply on a rejected
 *    requirement, so the resubmission an office had just asked for was refused
 *    by the same endpoint. An office wanting a clearer scan had to accept the
 *    bad one or raise a second requirement from scratch.
 */

beforeEach(function () {
    Storage::fake('local');
});

/** A filed application owned by owner@biztrack.local. */
function requirementApplication(string $businessName, string $registrationNumber): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $businessName,
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '2 Requirement St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    /*
     * Route it to the health office. Raising a requirement is still restricted
     * to an office actually handling the filing — that rule is untouched here,
     * and is a different question from who the applicant may MESSAGE.
     */
    ApplicationAssignment::firstOrCreate([
        'application_id' => $appId,
        'department_id' => Department::where('code', 'CHO')->value('id'),
    ]);

    return $appId;
}

/** A PNG with real bytes — `->create()` writes an empty file. */
function requirementFile(string $name = 'certificate.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
}

it('carries the office remark back to the applicant when a requirement is sent back', function () {
    $appId = requirementApplication('ABC Store', 'DTI-80001');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'request_type' => 'document',
            'title' => 'Health Certificate',
            'description' => 'Submit a valid health certificate.',
        ])->assertCreated()->json('data.id');

    // The applicant answers.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/respond", [
            'body' => 'Attached.',
            'document' => requirementFile(),
        ])->assertOk();

    // The office sends it back with a reason.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", [
            'outcome' => 'needs_resubmission',
            'remarks' => 'Please upload a clearer copy of the certificate.',
        ])->assertOk();

    // The applicant can read the reason and is told they may still act.
    $seen = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $requestId);

    expect($seen['status'])->toBe('needs_resubmission')
        ->and($seen['status_label'])->toBe('Needs Resubmission')
        ->and($seen['remarks'])->toBe('Please upload a clearer copy of the certificate.')
        ->and($seen['accepts_response'])->toBeTrue();
});

it('refuses to send a requirement back without saying why', function () {
    $appId = requirementApplication('ABC Store', 'DTI-80002');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'request_type' => 'document', 'title' => 'Health Certificate',
        ])->assertCreated()->json('data.id');

    foreach (['needs_resubmission', 'rejected'] as $outcome) {
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => $outcome])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remarks');
    }

    // Accepting needs no words: the outcome is the whole message.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
});

it('lets the applicant resubmit, and the answer returns to the same office', function () {
    $appId = requirementApplication('ABC Store', 'DTI-80003');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'request_type' => 'document', 'title' => 'Health Certificate',
        ])->assertCreated()->json('data.id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/respond", [
            'body' => 'First try.', 'document' => requirementFile('blurred.png'),
        ])->assertOk();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", [
            'outcome' => 'needs_resubmission', 'remarks' => 'Too blurred to read.',
        ])->assertOk();

    // The resubmission itself — this used to be refused with "This request is
    // closed, so you can no longer respond to it."
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/respond", [
            'body' => 'Clearer copy attached.', 'document' => requirementFile('clear.png'),
        ])->assertOk();

    $request = OfficerRequest::with('responses')->find($requestId);

    // Back for review, still the same requirement, with both attempts kept.
    expect($request->status)->toBe(OfficerRequestStatus::Submitted)
        ->and($request->responses)->toHaveCount(2)
        ->and($request->application_id)->toBe($appId);

    // It returns to the office that asked, and to nobody else.
    $inbox = fn (string $email) => collect(test()->withHeaders(authAs($email))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->pluck('id');

    expect($inbox('sanitary@biztrack.local'))->toContain($requestId)
        ->and($inbox('fire@biztrack.local'))->not->toContain($requestId);
});

it('lets the applicant answer again after a rejection', function () {
    /*
     * Inverted deliberately — this asserted that rejection was FINAL.
     *
     * The client has ruled the other way twice: "Do NOT mark the requirement as
     * completed after rejection", and then plainly, that if a document is
     * rejected the owner can send another one. So rejection is now the same
     * situation as needs_resubmission — refused, explained, still open — and
     * approval is the only thing that ends a requirement.
     *
     * Kept as an inverted test rather than deleted: the behaviour changed on
     * purpose, and a missing test would read as coverage someone dropped.
     */
    $appId = requirementApplication('ABC Store', 'DTI-80004');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'request_type' => 'document', 'title' => 'Health Certificate',
        ])->assertCreated()->json('data.id');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", [
            'outcome' => 'rejected', 'remarks' => 'The scan is unreadable.',
        ])->assertOk();

    $seen = fn () => collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $requestId);

    // Refused, and back with the applicant rather than shut.
    expect($seen()['status_label'])->toBe('Rejected')
        ->and($seen()['accepts_response'])->toBeTrue()
        ->and($seen()['awaits_applicant'])->toBeTrue()
        ->and($seen()['is_closed'])->toBeFalse()
        ->and($seen()['remarks'])->toBe('The scan is unreadable.');

    // And the replacement actually lands.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/respond", ['body' => 'A clean scan this time.'])
        ->assertOk();

    expect($seen()['status_label'])->toBe('For Review');

    // Approval is the one thing that closes it.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertOk();

    expect($seen()['is_closed'])->toBeTrue()
        ->and($seen()['accepts_response'])->toBeFalse();
});

it('lets the office set the status directly, and correct itself', function () {
    $appId = requirementApplication('ABC Store', 'DTI-80010');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'title' => 'Health Certificate',
        ])->assertCreated()->json('data.id');

    $status = fn () => OfficerRequest::find($requestId)->status->value;

    /*
     * The three the client named, set directly rather than only as the outcome
     * of reviewing a submission. An office has to be able to fix a mistake —
     * an approval clicked on the wrong row is otherwise permanent — so this is
     * not gated on a document being in the queue.
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
    expect($status())->toBe('fulfilled');

    // Approved by mistake — put it back.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", [
            'outcome' => 'pending', 'remarks' => 'Approved in error; still waiting on the certificate.',
        ])->assertOk();
    expect($status())->toBe('pending');

    // Reopened, so the applicant can act again.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/respond", ['body' => 'Here it is.'])
        ->assertOk();
    expect($status())->toBe('submitted');

    // Anything but an approval has to say why.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'rejected'])
        ->assertStatus(422)->assertJsonValidationErrors('remarks');

    // And "submitted" is not the office's to claim — it is what the applicant did.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'submitted', 'remarks' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('outcome');
});

it('tells the client which statuses an office may set', function () {
    $meta = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=5')->assertOk()->json('meta');

    // Served with the list so the control's words come from one place — the
    // enum's own label() — rather than a second copy in TypeScript.
    expect(collect($meta['office_statuses'])->pluck('value')->all())
        ->toBe(['pending', 'fulfilled', 'rejected']);
    expect(collect($meta['office_statuses'])->pluck('label')->all())
        ->toBe(['Pending', 'Approved', 'Rejected']);
});

it('shows the applicant an approved requirement as fulfilled', function () {
    $appId = requirementApplication('ABC Store', 'DTI-80005');

    $requestId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'request_type' => 'document', 'title' => 'Health Certificate',
        ])->assertCreated()->json('data.id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/respond", [
            'body' => 'Attached.', 'document' => requirementFile(),
        ])->assertOk();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$requestId}/close", ['outcome' => 'fulfilled'])
        ->assertOk();

    $seen = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $requestId);

    expect($seen['status'])->toBe('fulfilled')
        ->and($seen['accepts_response'])->toBeFalse()
        // Nothing was said, so nothing is shown — a stale remark under an
        // approved requirement reads as a fresh complaint.
        ->and($seen['remarks'])->toBeNull();
});

it('keeps two businesses’ requirements apart', function () {
    $abc = requirementApplication('ABC Store', 'DTI-80006');
    $xyz = requirementApplication('XYZ Cafe', 'DTI-80007');

    $abcReq = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$abc}/requests", [
            'request_type' => 'document', 'title' => 'Health Certificate for ABC',
        ])->assertCreated()->json('data.id');

    $xyzReq = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$xyz}/requests", [
            'request_type' => 'document', 'title' => 'Health Certificate for XYZ',
        ])->assertCreated()->json('data.id');

    $rows = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'));

    $abcRow = $rows->firstWhere('id', $abcReq);
    $xyzRow = $rows->firstWhere('id', $xyzReq);

    // Each requirement names its own filing, business and office, which is what
    // lets the owner tell "which requirement → which office → which business".
    expect($abcRow['application']['id'])->toBe($abc)
        ->and($abcRow['application']['business_name'])->toBe('ABC Store')
        ->and($abcRow['application']['tracking_id'])->not->toBeNull()
        ->and($abcRow['from_office']['code'])->toBe('CHO')
        ->and($xyzRow['application']['id'])->toBe($xyz)
        ->and($xyzRow['application']['business_name'])->toBe('XYZ Cafe')
        // Different filings, different tracking numbers, nothing shared.
        ->and($xyzRow['application']['tracking_id'])->not->toBe($abcRow['application']['tracking_id']);
});
