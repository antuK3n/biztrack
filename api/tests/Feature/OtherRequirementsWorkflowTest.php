<?php

use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\OfficerRequest;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * "Other Requirements", to the client's spec, end to end.
 *
 * The two rules that changed shape:
 *
 *  1. The office is taken from the SIGNED-IN ACCOUNT and nothing else. The
 *     composer used to ask "From office" with every office in the dropdown, so
 *     a City Health officer could raise a requirement the applicant saw as
 *     coming from the Fire Office — and, worse, which then appeared in the fire
 *     office's list and not in City Health's own. Hiding the field is not the
 *     fix; the field is gone and the endpoint ignores anyone who sends one.
 *
 *  2. Rejection returns the requirement to the applicant rather than ending it.
 *     "Do NOT mark the requirement as completed after rejection." The status
 *     vocabulary follows: nothing submitted is Pending, a submission is For
 *     Review, an approval is Approved, and a rejection is Needs Resubmission —
 *     which, like Pending, is waiting on the applicant.
 */

beforeEach(function () {
    Storage::fake('local');
});

/** A submitted filing routed to the given offices, owned by owner@biztrack.local. */
function requirementFiling(string $businessName, string $registrationNumber, array $offices = ['CHO']): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $businessName,
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Requirement Way', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    foreach ($offices as $code) {
        ApplicationAssignment::firstOrCreate([
            'application_id' => $appId,
            'department_id' => Department::where('code', $code)->value('id'),
        ]);
    }

    return $appId;
}

/** A PNG with real bytes — `->create()` writes an empty file. */
function requirementUpload(string $name = 'certificate.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
}

/* ── §1 / §16 the office comes from the account ──────────────────────────── */

it('stamps the requirement with the office of whoever raised it', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94001', ['CHO', 'BFP', 'BPLO']);

    $cases = [
        'sanitary@biztrack.local' => 'CHO',
        'fire@biztrack.local' => 'BFP',
        'bplo@biztrack.local' => 'BPLO',
    ];

    foreach ($cases as $email => $expected) {
        $row = test()->withHeaders(authAs($email))
            ->postJson("/api/v1/applications/{$appId}/requests", [
                'title' => "Requirement from {$expected}",
            ])->assertCreated()->json('data');

        // No office was sent, and the right one came back — for every office,
        // not just the one that happened to be tested first.
        expect($row['from_office']['code'])->toBe($expected);
    }
});

it('ignores an office sent by the client', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94002', ['CHO', 'BFP']);

    /*
     * The whole point of §16: the rule is enforced by the backend, not by a
     * hidden form field. A City Health officer posting the fire office's id
     * still raises a City Health requirement.
     */
    $row = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", [
            'title' => 'Health Certificate',
            'department_id' => Department::where('code', 'BFP')->value('id'),
        ])->assertCreated()->json('data');

    expect($row['from_office']['code'])->toBe('CHO');

    // And it lands in City Health's list, not the fire office's.
    $inList = fn (string $email) => collect(test()->withHeaders(authAs($email))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))->pluck('id');

    expect($inList('sanitary@biztrack.local'))->toContain($row['id'])
        ->and($inList('fire@biztrack.local'))->not->toContain($row['id']);
});

it('refuses to raise a requirement from an account with no office', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94003', ['CHO']);

    // The super admin belongs to no office. It holds no `request.create` either,
    // so the route refuses first — the point is that there is no path by which a
    // requirement gets written with no office against it.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'From nobody'])
        ->assertForbidden();

    expect(OfficerRequest::whereNull('department_id')->count())->toBe(0);
});

/* ── §2 no Type, §15 the fields the form actually offers ─────────────────── */

it('needs nothing but a name, and files itself as a document request', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94004', ['CHO']);

    // No `request_type` sent — the composer no longer asks, because an Other
    // Requirement is a document request by definition.
    $row = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Health Certificate'])
        ->assertCreated()->json('data');

    expect($row['request_type'])->toBe('document')
        ->and($row['status'])->toBe('pending')
        ->and($row['status_label'])->toBe('Pending');
});

it('carries the deadline, the note and the office’s reference file', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94005', ['CHO']);

    $row = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->post("/api/v1/applications/{$appId}/requests", [
            'title' => 'Health Certificate',
            'description' => 'Submit the certificate for every food handler.',
            'additional_remarks' => 'Bring the originals to the counter as well.',
            'due_date' => now()->addWeek()->toDateString(),
            'reference' => requirementUpload('blank-form.png'),
        ])->assertCreated()->json('data');

    expect($row['description'])->toBe('Submit the certificate for every food handler.')
        // A note written when the requirement was RAISED, and not the same
        // column as the office's later verdict.
        ->and($row['additional_remarks'])->toBe('Bring the originals to the counter as well.')
        ->and($row['remarks'])->toBeNull()
        ->and($row['due_date'])->not->toBeNull()
        ->and($row['reference']['name'])->toBe('blank-form.png');

    // The applicant can actually fetch the template they were pointed at.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->get("/api/v1/requests/{$row['id']}/reference")->assertOk();

    // An unrelated office cannot.
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->get("/api/v1/requests/{$row['id']}/reference")->assertForbidden();
});

/* ── §3 / §13 the business it belongs to ─────────────────────────────────── */

it('names the business and its number, and keeps two businesses apart', function () {
    $abc = requirementFiling('ABC Store', 'DTI-94006', ['CHO']);
    $xyz = requirementFiling('XYZ Cafe', 'DTI-94007', ['CHO']);

    foreach ([$abc, $xyz] as $appId) {
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Health Certificate'])
            ->assertCreated();
    }

    $rows = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->whereIn('application.id', [$abc, $xyz])->values();

    expect($rows)->toHaveCount(2);

    /*
     * Same office, same requirement name, same owner, two businesses. Business
     * name plus business number is what tells them apart — and the numbers must
     * actually differ, or the pair is indistinguishable on screen.
     */
    $abcRow = $rows->firstWhere('application.id', $abc);
    $xyzRow = $rows->firstWhere('application.id', $xyz);

    expect($abcRow['application']['business_name'])->toBe('ABC Store')
        ->and($xyzRow['application']['business_name'])->toBe('XYZ Cafe')
        ->and($abcRow['application']['tracking_id'])->not->toBe($xyzRow['application']['tracking_id'])
        ->and($abcRow['application']['business_id'])->not->toBe($xyzRow['application']['business_id']);
});

/* ── §5 / §6 / §9 the status the client specified ────────────────────────── */

it('walks pending → for review → needs resubmission → for review → approved', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94008', ['CHO']);

    $id = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Health Certificate'])
        ->assertCreated()->json('data.id');

    $seen = function () use ($id) {
        return collect(test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
            ->firstWhere('id', $id);
    };

    // Nothing submitted → Pending, and it is the applicant's move.
    expect($seen()['status_label'])->toBe('Pending')
        ->and($seen()['awaits_applicant'])->toBeTrue()
        ->and($seen()['awaits_office'])->toBeFalse();

    // Submitted → For Review, and the move passes to the office.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->post("/api/v1/requests/{$id}/respond", ['body' => 'Attached.', 'document' => requirementUpload('blurred.png')])
        ->assertOk();

    expect($seen()['status_label'])->toBe('For Review')
        ->and($seen()['awaits_applicant'])->toBeFalse()
        ->and($seen()['awaits_office'])->toBeTrue();

    // Rejected → back to the applicant, NOT completed.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$id}/close", [
            'outcome' => 'needs_resubmission',
            'remarks' => 'Please submit a clearer copy of the Health Certificate.',
        ])->assertOk();

    expect($seen()['status_label'])->toBe('Needs Resubmission')
        ->and($seen()['awaits_applicant'])->toBeTrue()
        ->and($seen()['is_closed'])->toBeFalse()
        ->and($seen()['remarks'])->toBe('Please submit a clearer copy of the Health Certificate.');

    // Resubmitted → For Review again.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->post("/api/v1/requests/{$id}/respond", ['body' => 'Clearer copy.', 'document' => requirementUpload('clear.png')])
        ->assertOk();

    expect($seen()['status_label'])->toBe('For Review');

    // Approved → done.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$id}/close", ['outcome' => 'fulfilled'])
        ->assertOk();

    expect($seen()['status_label'])->toBe('Approved')
        ->and($seen()['is_closed'])->toBeTrue()
        ->and($seen()['awaits_applicant'])->toBeFalse();
});

/* ── §10 the submission history ──────────────────────────────────────────── */

it('keeps every submission with the verdict that was passed on it', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94009', ['CHO']);

    $id = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Health Certificate'])
        ->assertCreated()->json('data.id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->post("/api/v1/requests/{$id}/respond", ['body' => 'First try.', 'document' => requirementUpload('one.png')])
        ->assertOk();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$id}/close", ['outcome' => 'needs_resubmission', 'remarks' => 'Document is unclear.'])
        ->assertOk();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->post("/api/v1/requests/{$id}/respond", ['body' => 'Second try.', 'document' => requirementUpload('two.png')])
        ->assertOk();

    $seen = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))->firstWhere('id', $id);

    $history = collect($seen['responses']);
    expect($history)->toHaveCount(2);

    /*
     * The verdict is stamped on the submission it judged. On the parent alone
     * there is one remark for the whole requirement, so after the second
     * submission the applicant would read "Document is unclear" as though it
     * were about the copy they had just sent.
     */
    expect($history[0]['number'])->toBe(1)
        ->and($history[0]['review_status_label'])->toBe('Needs Resubmission')
        ->and($history[0]['review_remarks'])->toBe('Document is unclear.')
        ->and($history[0]['document']['filename'])->toBe('one.png');

    // The newest is with the office and has no verdict yet.
    expect($history[1]['number'])->toBe(2)
        ->and($history[1]['review_outcome'])->toBeNull()
        ->and($history[1]['document']['filename'])->toBe('two.png');
});

/* ── §11 / §12 only the office that asked ────────────────────────────────── */

it('gives each office its own requirements on one shared filing', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94010', ['CHO', 'BFP', 'OBO']);

    $raise = fn (string $email, string $title) => test()->withHeaders(authAs($email))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => $title])
        ->assertCreated()->json('data.id');

    $health = $raise('sanitary@biztrack.local', 'Health Certificate');
    $fire = $raise('fire@biztrack.local', 'Fire Safety Certificate');
    $obo = $raise('obo@biztrack.local', 'Occupancy Document');

    $listOf = fn (string $email) => collect(test()->withHeaders(authAs($email))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))->pluck('id');

    // Each office sees its own and nobody else's, on a filing all three share.
    expect($listOf('sanitary@biztrack.local'))->toContain($health)->not->toContain($fire, $obo);
    expect($listOf('fire@biztrack.local'))->toContain($fire)->not->toContain($health, $obo);
    expect($listOf('obo@biztrack.local'))->toContain($obo)->not->toContain($health, $fire);

    // The applicant sees all three, each labelled with the office that asked.
    $owner = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->whereIn('id', [$health, $fire, $obo]);

    expect($owner->pluck('from_office.code')->sort()->values()->all())->toBe(['BFP', 'CHO', 'OBO']);
});

it('lets only the office that asked rule on the answer', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94011', ['CHO', 'BFP']);

    $id = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Health Certificate'])
        ->assertCreated()->json('data.id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->post("/api/v1/requests/{$id}/respond", ['body' => 'Attached.', 'document' => requirementUpload()])
        ->assertOk();

    // Routed to the same filing, and still not theirs to approve.
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/requests/{$id}/close", ['outcome' => 'fulfilled'])
        ->assertForbidden();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$id}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
});
