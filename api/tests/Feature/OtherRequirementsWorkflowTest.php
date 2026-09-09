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
        /*
         * RA 10173 consent, carried on the filing itself.
         *
         * submit() refuses without it — it is the first of two gates there, and
         * the lawful basis for processing anything else on the form. This is a
         * PRECONDITION of this fixture, not its subject: every test below is
         * about Other Requirements, and a filing that never reached an office
         * queue cannot have a requirement raised against it.
         */
        'data_privacy_consent' => true,
    ])->assertCreated()->json('data.id');

    // Lands on For Approval, not Pending Payment: BPLO reads the main form
    // before any bill is raised. Nothing here needs a paid filing, so the
    // fixture stops at submit rather than driving it further.
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

/*
 * ── Every office, BPLO included ──────────────────────────────────────────────
 *
 * The test above proves separability for three offices. This one proves it for
 * all six AND closes the exception that was left in it.
 *
 * Six, not seven: CMO-MARKET was in this list until the Market Clearance was
 * taken out of the system on 6 September 2026. It is named here rather than
 * quietly dropped because the office no longer exists to be seeded, so asking
 * for it produced an assignment with no department at all rather than an
 * honest failure.
 *
 * BPLO used to read every office's requirements, and the reasoning was written
 * down: it coordinates the other offices' clearances and has to be able to
 * unblock a filing when one goes quiet. The client has ruled the other way —
 * "sa fire ganon, sa kanya lang din dapat, di dapat mag-reflect sa BPLO" — so
 * an office is an office: BPLO sees what BPLO asked for.
 *
 * CONSEQUENCE, recorded because it is a real loss and not an oversight: nobody
 * can now unblock another office's requirement. If the fire office raises one
 * and then goes quiet, the applicant answers into a queue no one is reading and
 * BPLO cannot approve it on their behalf. The escape hatch that remains is the
 * super admin, who belongs to no office and still reads the register — see the
 * test below.
 */
it('gives every office its own requirements, and BPLO is an office like the rest', function () {
    $offices = [
        'bplo@biztrack.local' => 'BPLO',
        'sanitary@biztrack.local' => 'CHO',
        'fire@biztrack.local' => 'BFP',
        'obo@biztrack.local' => 'OBO',
        'cenro@biztrack.local' => 'CENRO',
        'zoning@biztrack.local' => 'CPDO',
    ];

    // One filing every office is on, which is the hard case: sharing a filing
    // is exactly what used to hand each office all six offices' requirements.
    $appId = requirementFiling('ABC Store', 'DTI-94020', array_values($offices));

    $raised = [];
    foreach ($offices as $email => $code) {
        $raised[$code] = test()->withHeaders(authAs($email))
            ->postJson("/api/v1/applications/{$appId}/requests", ['title' => "Document for {$code}"])
            ->assertCreated()->json('data.id');
    }

    foreach ($offices as $email => $code) {
        $seen = collect(test()->withHeaders(authAs($email))
            ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))->pluck('id');

        expect($seen)->toContain($raised[$code]);

        foreach ($raised as $otherCode => $id) {
            if ($otherCode === $code) {
                continue;
            }
            expect($seen->contains($id))
                ->toBeFalse("{$code} can see {$otherCode}'s requirement");
        }
    }

    // And the applicant sees all six, each carrying the name of the office
    // that asked — the other half of the claim, and the half the owner reads.
    $owner = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))
        ->whereIn('id', array_values($raised));

    expect($owner)->toHaveCount(6);
    expect($owner->pluck('from_office.code')->sort()->values()->all())
        ->toBe(['BFP', 'BPLO', 'CENRO', 'CHO', 'CPDO', 'OBO']);
});

it('does not let BPLO rule on a requirement another office raised', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94021', ['CHO', 'BPLO']);

    $id = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Health Certificate'])
        ->assertCreated()->json('data.id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->post("/api/v1/requests/{$id}/respond", ['body' => 'Attached.', 'document' => requirementUpload()])
        ->assertOk();

    // Coordinating the filing is not the same as judging another office's
    // document: only City Health knows whether that certificate satisfies it.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$id}/close", ['outcome' => 'fulfilled'])
        ->assertForbidden();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$id}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
});

/*
 * The one reader left unbounded, and why.
 *
 * The rule the client set is about OFFICES — each sees what it asked for. The
 * super admin is not one: the account belongs to no department (store() refuses
 * it a requirement for exactly that reason) and its job is the register itself.
 * Scoping it by `department_id` would scope it to nothing, which would not be
 * "the admin sees only its own" but "the admin sees none", and would remove the
 * only remaining way to look at a requirement an office has abandoned.
 */
it('keeps the register-wide view for the super admin, who belongs to no office', function () {
    $appId = requirementFiling('ABC Store', 'DTI-94022', ['CHO', 'BFP']);

    $health = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Health Certificate'])
        ->assertCreated()->json('data.id');
    $fire = test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/requests", ['title' => 'Fire Safety Certificate'])
        ->assertCreated()->json('data.id');

    $seen = collect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=200')->assertOk()->json('data'))->pluck('id');

    expect($seen)->toContain($health)->toContain($fire);
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

/* ── the list's own controls: narrowing and ordering ─────────────────────── */

/*
 * The screen offered a "Sort ⇅ Filter ▽" that was drawn and inert — the
 * prototype's ornament, shipped as if it worked. Both are real now, and both
 * are QUERY PARAMETERS rather than a pass over the downloaded page: the list is
 * capped at fifty rows and a browser-side filter would answer "no rejected
 * requirements" to an office whose rejected ones are on page two.
 */
it('narrows the requirements list to one status, over the whole register', function () {
    $appId = requirementFiling('Filter Test Store', 'DTI-94800', ['CHO']);
    $office = authAs('sanitary@biztrack.local');

    // Three requirements, taken to three different statuses.
    $ids = [];
    foreach (['Sanitary permit', 'Water test', 'Health cards'] as $title) {
        $ids[$title] = test()->withHeaders($office)
            ->postJson("/api/v1/applications/{$appId}/requests", ['title' => $title])
            ->assertCreated()->json('data.id');
    }

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$ids['Water test']}/close", ['outcome' => 'fulfilled'])
        ->assertOk();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/requests/{$ids['Health cards']}/close", [
            'outcome' => 'rejected',
            'remarks' => 'The scan is unreadable.',
        ])->assertOk();

    $titlesWithStatus = function (string $status) {
        return collect(
            test()->withHeaders(authAs('sanitary@biztrack.local'))
                ->getJson("/api/v1/requests?status={$status}&per_page=200")
                ->assertOk()->json('data')
        )->pluck('subject');
    };

    expect($titlesWithStatus('pending'))->toContain('Sanitary permit')
        ->and($titlesWithStatus('pending'))->not->toContain('Water test')
        ->and($titlesWithStatus('fulfilled'))->toContain('Water test')
        ->and($titlesWithStatus('fulfilled'))->not->toContain('Health cards')
        ->and($titlesWithStatus('rejected'))->toContain('Health cards')
        ->and($titlesWithStatus('rejected'))->not->toContain('Sanitary permit');
});

it('orders the requirements list oldest-first when asked to', function () {
    $appId = requirementFiling('Order Test Store', 'DTI-94810', ['CHO']);
    $office = authAs('sanitary@biztrack.local');

    foreach (['First raised', 'Second raised', 'Third raised'] as $title) {
        test()->withHeaders($office)
            ->postJson("/api/v1/applications/{$appId}/requests", ['title' => $title])
            ->assertCreated();
    }

    $subjects = function (string $query) {
        return collect(
            test()->withHeaders(authAs('sanitary@biztrack.local'))
                ->getJson("/api/v1/requests?{$query}&per_page=200")
                ->assertOk()->json('data')
        )->pluck('subject')
            ->filter(fn ($s) => str_ends_with($s, ' raised'))
            ->values()->all();
    };

    // Default is unchanged — newest first, as the screen has always said.
    expect($subjects('sort=recent'))->toBe(['Third raised', 'Second raised', 'First raised'])
        ->and($subjects(''))->toBe(['Third raised', 'Second raised', 'First raised'])
        ->and($subjects('sort=oldest'))->toBe(['First raised', 'Second raised', 'Third raised']);
});

it('refuses an ordering it does not implement rather than silently ignoring it', function () {
    // A rejected `sort` has to 422. Accepting the word and quietly returning
    // the default is how a screen ends up showing one order while its control
    // claims another.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/requests?sort=alphabetical')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

it('sends the filter its options, including the statuses no office can set', function () {
    $meta = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/requests?per_page=1')->assertOk()->json('meta');

    $settable = collect($meta['office_statuses'])->pluck('value');
    $all = collect($meta['statuses'])->pluck('value');

    // The filter needs every status a row can HOLD; the buttons need only the
    // ones an office may SET. Two lists, and the longer one is a superset.
    expect($all)->toContain('pending', 'submitted', 'fulfilled', 'needs_resubmission', 'rejected')
        ->and($settable)->not->toContain('submitted')
        ->and($settable->diff($all))->toBeEmpty()
        // The words come from the enum, so the filter and the chips agree.
        ->and(collect($meta['statuses'])->firstWhere('value', 'submitted')['label'])->toBe('For Review')
        ->and(collect($meta['statuses'])->firstWhere('value', 'fulfilled')['label'])->toBe('Approved');
});
