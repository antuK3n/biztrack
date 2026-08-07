<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOfficeForm;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\FeeAssessment;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\ClearanceService;
use App\Support\PermitFees;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The LGU clearance stage (docs/clearances-before-payment.md).
 *
 * The six clearances are the last thing decided before Review & Submit, and the
 * whole filing — business permit and every clearance chosen — is assessed once
 * at submit and paid for once. So the stage is open exactly while the
 * application is a draft, and shut from submission onwards.
 *
 * These tests used to assert the opposite: the stage opened when the FIRST
 * payment cleared, each clearance applied for accrued onto a running balance, a
 * second payment settled it and a gate held the permit until it did. All of
 * that is gone — see the SUPERSEDED header on
 * docs/clearances-after-payment.md — and the tests that pinned it down have
 * been rewritten rather than deleted, because the behaviour they described has
 * a replacement that still needs pinning down.
 */

/**
 * A draft owned by `owner@biztrack.local`, asking for the business permit and
 * nothing else yet — which is where the clearance stage is open.
 *
 * The fee profile is deliberately full. An empty one prices several clearances
 * at zero (no employees means no health certificates, no floor area means no
 * sanitary inspection fee), and a test asserting "the fee went up" against a
 * profile that cannot produce a fee proves nothing.
 */
function draftClearanceApplication(string $name = 'Clearance Stage Cafe'): Application
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-CLR-001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '12 Clearance Ave.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'fee_profile' => [
            'gross_sales' => 2000000,
            'capitalization' => 500000,
            'employees' => 12,
            'employees_in_lgu' => 6,
            'floor_area_sqm' => 120,
            'storeys' => 2,
            'business_structure' => 'sole_proprietorship',
            'property_use' => 'non_residential',
        ],
    ])->assertCreated()->json('data.id');

    return Application::findOrFail($appId);
}

/** The same filing, submitted: the clearances on it are now fixed. */
function submittedClearanceApplication(string $name = 'Submitted Stage Store'): Application
{
    $app = draftClearanceApplication($name);

    test()->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    return $app->fresh();
}

/** Every fee line on a filing's Tax Order of Payment, lower-cased and joined. */
function topOrderLabels(Application $app): string
{
    $fee = FeeAssessment::where('application_id', $app->id)->firstOrFail();

    return strtolower(implode(' | ', array_column($fee->line_items, 'label')));
}

beforeEach(function () {
    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');
});

// --- the shape ---------------------------------------------------------------

it('lists the six clearances and never the permit the application is for', function () {
    $app = draftClearanceApplication();

    $rows = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('data');

    $codes = collect($rows)->pluck('permit_type.code');

    expect($codes)->toHaveCount(6)
        ->and($codes->all())->toBe(['ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY', 'MARKET'])
        // The mayor's permit is the outcome of the application, not a clearance
        // to pick, so it is not on the chooser at all.
        ->and($codes)->not->toContain(PermitType::OUTCOME_CODE);
});

it('carries the full contract shape on every row', function () {
    $app = draftClearanceApplication();

    $row = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'SANITARY');

    expect($row)->toHaveKeys([
        'permit_type', 'state', 'has_office_form', 'office_form_complete',
        'held_document', 'assignment', 'fee_preview',
    ]);
    expect($row['permit_type'])->toHaveKeys(['id', 'code', 'name', 'department']);
    expect($row['permit_type']['department'])->toHaveKeys(['code', 'name']);
    expect($row['permit_type']['department']['code'])->toBe('CHO');
    expect($row['state'])->toBe('available');
    // SANITARY is one of the clearances with an applicant-facing sheet;
    // nothing is saved on it yet.
    expect($row['has_office_form'])->toBeTrue()
        ->and($row['office_form_complete'])->toBeFalse()
        ->and($row['held_document'])->toBeNull()
        ->and($row['assignment'])->toBeNull()
        ->and($row['fee_preview'])->toBeString();

    /*
     * All six open a form now, and Market was the last to.
     *
     * This assertion used to be a PAIR — zoning true, market false — recording
     * that the difference between the two was which paper the city had given
     * us. Zoning was settled when CPDD sent MCG-CPDD-FO-003 v1.2 and its sheet
     * was rebuilt against the real form. Market was settled the other way:
     * checklist item 109 says the city has no paper version and asks for one to
     * be created, so its sheet is written rather than transcribed (see the
     * header of web OfficeFormStep.tsx for the three questions and why each is
     * there).
     *
     * Asserted over the whole set rather than over two named codes, because the
     * claim is no longer about a pair. Every clearance the register seeds opens
     * a sheet, so a seventh one added without one has to come here and say so.
     */
    $rows = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'));
    expect($rows->pluck('has_office_form')->all())->each->toBeTrue()
        ->and($rows->firstWhere('permit_type.code', 'MARKET')['has_office_form'])->toBeTrue();
});

it('carries no ledger in meta, because a draft owes nothing', function () {
    $app = draftClearanceApplication();

    $meta = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('meta');

    /*
     * `total_assessed`, `total_paid` and `balance_due` were here while a
     * clearance applied for after payment raised a balance. On a draft every
     * one of them would read zero, and a zero that actually means "not assessed
     * yet" is a worse answer than no figure at all — it reads as "these are
     * free".
     */
    expect(array_keys($meta))->toBe(['unlocked', 'locked_reason']);
});

// --- the unlock rule ---------------------------------------------------------

it('opens the stage while the application is still a draft', function () {
    $app = draftClearanceApplication();

    $meta = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('meta');

    expect($meta['unlocked'])->toBeTrue()
        ->and($meta['locked_reason'])->toBeNull();
});

it('shuts the stage the moment the filing is submitted, with a reason that says the choice is made', function () {
    $app = submittedClearanceApplication();

    $meta = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('meta');

    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toBeString()
        // Not "pay first". The filing is with the office and the clearances on
        // it were settled at submission.
        ->and($meta['locked_reason'])->toContain('decided when you submitted');
});

it('refuses every write once the filing has been submitted', function () {
    $app = submittedClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    // Nothing leaked through: no permit type attached, no document stored.
    expect($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0);
});

it('keeps the stage shut on a filing that was rejected', function () {
    $app = submittedClearanceApplication();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])->assertOk();

    authAs('owner@biztrack.local');
    $meta = $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('meta');

    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toContain('was not approved');
});

// --- applying: the clearance joins the one Tax Order of Payment --------------

it('writes no fee assessment when a clearance is applied for on a draft', function () {
    $app = draftClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    /*
     * There is nothing to bill yet. Assessing here would put a Tax Order of
     * Payment against a filing nobody has sent, and the accrual that used to
     * make that necessary is gone: `assessFees` runs once, at submit, over
     * exactly the permit types the applicant finished with.
     */
    expect(FeeAssessment::where('application_id', $app->id)->exists())->toBeFalse()
        ->and($app->fresh()->permitTypes->pluck('code'))->toContain('ZONING');
});

it('puts exactly that office’s fee lines on the Tax Order of Payment and no other office’s', function () {
    $plain = submittedClearanceApplication('Plain Permit Cafe');

    $withZoning = draftClearanceApplication('Zoned Permit Cafe');
    $this->postJson("/api/v1/applications/{$withZoning->id}/clearances/ZONING/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$withZoning->id}/submit")->assertOk();

    $before = FeeAssessment::where('application_id', $plain->id)->firstOrFail();
    $after = FeeAssessment::where('application_id', $withZoning->id)->firstOrFail();

    $added = array_values(array_diff(
        array_column($after->line_items, 'label'),
        array_column($before->line_items, 'label')
    ));
    $removed = array_values(array_diff(
        array_column($before->line_items, 'label'),
        array_column($after->line_items, 'label')
    ));

    // Sec. 3.D.01: filing 45 + land use verification 345 + processing 345.
    // Every added line is the City Planning Office's and no other's.
    expect($added)->toHaveCount(3);
    foreach ($added as $label) {
        expect(strtolower($label))->toContain('locational clearance');
    }
    // Choosing a clearance is additive: nothing the business permit is charged
    // for is disturbed by asking a second office for something.
    expect($removed)->toBe([])
        ->and(round((float) $after->total_amount - (float) $before->total_amount, 2))->toBe(735.0);
});

it('bills only the chosen offices, not every office', function () {
    $app = draftClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    $labels = topOrderLabels($app);

    // The City Health Office's line is there...
    expect($labels)->toContain('sanitary inspection fee');
    // ...and no other office's clearance-gated line came with it.
    expect($labels)->not->toContain('locational clearance')
        ->and($labels)->not->toContain('fire safety inspection certificate fee');
});

/*
 * The whole point of the reorder, in one test.
 *
 * The client's instruction was that one Tax Order of Payment covers the
 * business permit AND every clearance chosen. `assessFees` at submit was
 * expected to already do this, because FeeCalculator gates each rule on the
 * application's permit types — but "expected to" is not the same as checked,
 * and this is the check.
 */
it('bills the business permit and every clearance chosen on one Tax Order of Payment', function () {
    $app = draftClearanceApplication('Four Office Cafe');

    foreach (['ZONING', 'SANITARY', 'FSIC', 'OCCUPANCY'] as $code) {
        $this->postJson("/api/v1/applications/{$app->id}/clearances/{$code}/apply")->assertOk();
    }
    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    $labels = topOrderLabels($app);

    expect($labels)
        ->toContain('locational clearance')                      // CPDO
        ->toContain('sanitary inspection fee')                   // CHO
        ->toContain('fire safety inspection certificate fee')    // BFP
        ->toContain('certificate of use/occupancy');             // OBO

    // And none of the four is on a filing that asked for the business permit
    // alone — which is what makes the four above the clearances' own lines
    // rather than something every filing pays.
    $plain = topOrderLabels(submittedClearanceApplication('One Office Cafe'));
    expect($plain)
        ->not->toContain('locational clearance')
        ->not->toContain('sanitary inspection fee')
        ->not->toContain('fire safety inspection certificate fee')
        ->not->toContain('certificate of use/occupancy');

    // And it is payable in one go — the amount charged is the whole assessment.
    $assessed = (float) FeeAssessment::where('application_id', $app->id)->firstOrFail()->total_amount;
    $paid = (float) $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertCreated()->json('data.amount');

    expect($paid)->toBe($assessed)
        ->and(PermitFees::balance(Application::findOrFail($app->id))['balance_due'])->toBe(0.0);
});

it('routes every chosen clearance to its own office when the payment clears', function () {
    $app = draftClearanceApplication();
    $cho = Department::where('code', 'CHO')->firstOrFail();

    $row = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertOk()->json('data');

    /*
     * Nothing is routed yet, and that is deliberate rather than incidental.
     * `assigned_at` is the start of the office's service-time clock that
     * ProcessingTimeAnalytics, StaffingSimulation and DashboardAnalytics all
     * measure; stamping it while the applicant is still typing would charge CHO
     * for the days the draft sat open.
     */
    expect($row['state'])->toBe('applied')
        ->and($row['assignment'])->toBeNull()
        ->and(ApplicationAssignment::where('application_id', $app->id)->exists())->toBeFalse();

    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $cho->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status->value)->toBe('pending');

    authAs('owner@biztrack.local');
    $after = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'SANITARY');
    expect($after['assignment']['id'])->toBe($assignment->id);
});

it('previews what applying will add before it is applied for', function () {
    $app = draftClearanceApplication();

    $preview = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'ZONING')['fee_preview'];

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    $plain = submittedClearanceApplication('Preview Baseline Cafe');

    $withZoning = (float) FeeAssessment::where('application_id', $app->id)->firstOrFail()->total_amount;
    $without = (float) FeeAssessment::where('application_id', $plain->id)->firstOrFail()->total_amount;

    // The preview is the promise; the Tax Order of Payment is what it cost.
    expect($preview)->toBe('₱735.00')
        ->and(round($withZoning - $without, 2))->toBe(735.0);
});

// --- un-applying -------------------------------------------------------------

it('takes the clearance and its fee lines back off when it is un-applied', function () {
    $app = draftClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk()->json();

    expect($body['data']['state'])->toBe('available')
        ->and($body['data']['assignment'])->toBeNull()
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING');

    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    // A clearance withdrawn before submission is not billed for.
    expect(topOrderLabels($app))->not->toContain('locational clearance');
});

/*
 * Defence in depth, and it says so.
 *
 * `officeHasActed` cannot be reached through the wizard any more: withdrawing
 * is only possible on a draft, and no office holds an assignment on a draft.
 * The guard stays because the endpoint is not the only way in and because the
 * rule it states — an office that has started work has done something a
 * cancel button must not erase — outlives the flow that made it reachable.
 */
it('will not let the applicant withdraw a clearance the office has already acted on', function () {
    $app = draftClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'CHO')->firstOrFail()->id,
        'status' => 'in_progress',
        'assigned_at' => now(),
    ]);

    authAs('owner@biztrack.local');
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertStatus(422);

    expect($app->fresh()->permitTypes->pluck('code'))->toContain('SANITARY');
});

// --- the held copy: no fee ---------------------------------------------------

it('adds no fee and no permit type when a held copy is uploaded', function () {
    $app = draftClearanceApplication();

    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe('submitted')
        ->and($body['data']['held_document']['name'])->toBe('sanitary.pdf')
        ->and($body['data']['held_document']['size'])->toBeGreaterThan(0)
        // No permit type means no form, no assignment and no fee — the whole
        // reason submitting a copy is not the same act as applying.
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('SANITARY')
        ->and($body['data']['assignment'])->toBeNull();

    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    expect(topOrderLabels($app))->not->toContain('sanitary inspection fee');
});

it('records the held copy through the same mechanism the wizard uses', function () {
    $app = draftClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $doc = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)
        ->with('documentType')
        ->firstOrFail();

    // Same document-type convention as DocumentController's path, so an officer
    // reading the attachment list cannot tell which screen it arrived through.
    expect($doc->documentType->code)->toBe('HELD_SANITARY')
        ->and($doc->documentType->name)->toContain('already held');
});

it('replaces an earlier held copy rather than stacking them', function () {
    $app = draftClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('old.pdf', 20, 'application/pdf'),
    ])->assertCreated();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('new.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $held = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)->get();

    expect($held)->toHaveCount(1)
        ->and($held->first()->original_filename)->toBe('new.pdf');
});

it('removes the held copy and its file', function () {
    $app = draftClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $path = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)->firstOrFail()->stored_path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")
        ->assertOk()->json();

    expect($body['data']['state'])->toBe('available')
        ->and($body['data']['held_document'])->toBeNull()
        ->and(ApplicationDocument::where('application_id', $app->id)->where('permit_type_id', $sanitary->id)->count())->toBe(0)
        // A "removed" document still sitting on disk is not removed: it stays
        // downloadable through /documents/{id}/download for as long as it is there.
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('keeps applying and submitting mutually exclusive', function () {
    $app = draftClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);
});

/*
 * The refusal above is right; for four days it was also unactionable — CLR-1.
 *
 * It told the applicant to withdraw, and 9e30b44 had removed every control that
 * could. The test directly above passed throughout, because it recovers by
 * calling DELETE /clearances/SANITARY/apply itself — an endpoint with no caller
 * on any screen. It proved the escape existed at the HTTP layer, which was true
 * and irrelevant to a person holding a mouse.
 *
 * So these are the same two endpoints in the same order, asserted as the SWITCH
 * the clearance card now performs rather than as a recovery step inside a test.
 * Both directions, because the asymmetry was the defect: held → applied has
 * always resolved itself in one click, and applied → held was impossible.
 */
it('lets an applicant switch from applying for a clearance to filing their own copy', function () {
    $app = draftClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    // What the Submit dialog does on an applied card: withdraw, then upload.
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe('submitted')
        ->and($body['data']['held_document']['name'])->toBe('sanitary.pdf')
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('SANITARY');

    // And back again — what Apply does over an uploaded copy, once the
    // applicant has agreed to lose the file.
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")->assertOk();
    $back = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertOk()->json();

    expect($back['data']['state'])->toBe('applied')
        ->and($back['data']['held_document'])->toBeNull()
        ->and(ApplicationDocument::where('application_id', $app->id)
            ->where('permit_type_id', $sanitary->id)->count())->toBe(0);

    // The filing is billed for exactly the leg it ended on.
    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();
    expect(topOrderLabels($app))->toContain('sanitary inspection fee');
});

/*
 * The invariant the switch must not spend, asserted as a count.
 *
 * No filing in the register has ever carried both an `application_permit_types`
 * row and an `application_documents.permit_type_id` row for the same clearance,
 * and everything downstream depends on that: FeeCalculator bills the permit-type
 * side, routeToDepartments raises an assignment from it, approveAndIssue turns
 * it into a Permit — a legal instrument that would then sit beside the
 * applicant's own copy of the same certificate on their profile.
 *
 * The switch is two guarded requests rather than one endpoint taught to write
 * both sides, so the state between them is "neither", never "both". This walks
 * the whole sequence and counts after every step, because a fix that made
 * storeHeld auto-withdraw internally would pass every test above and could
 * still leave both rows if its guards and unapply's ever drifted apart.
 */
it('never leaves a filing holding both an application and a copy of one clearance', function () {
    $app = draftClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $both = function () use ($app, $sanitary) {
        $applied = $app->fresh()->permitTypes->contains(fn ($pt) => $pt->id === $sanitary->id);
        $held = ApplicationDocument::where('application_id', $app->id)
            ->where('permit_type_id', $sanitary->id)->exists();

        return $applied && $held;
    };

    expect($both())->toBeFalse();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    expect($both())->toBeFalse();

    // The forbidden order, refused: upload while still applied.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);
    expect($both())->toBeFalse();

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();
    expect($both())->toBeFalse();

    // The other forbidden order, refused: apply while a copy is on file.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);
    expect($both())->toBeFalse();
});

/*
 * The first leg of the switch can be refused, and then the second must not run.
 *
 * The card withdraws before it uploads for exactly this reason: `unapply` holds
 * three guards `storeHeld` does not — nothing issued for this type, the office
 * has not started, and the business record is still there to re-price against.
 * If an auto-withdraw were ever folded into `storeHeld`, this is the assertion
 * that would go red, because the copy would be filed on a clearance the
 * applicant is not allowed to take back.
 */
it('will not file a copy of a clearance whose office has already started work', function () {
    $app = draftClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'CHO')->firstOrFail()->id,
        'status' => 'in_progress',
        'assigned_at' => now(),
    ]);

    authAs('owner@biztrack.local');
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    expect($app->fresh()->permitTypes->pluck('code'))->toContain('SANITARY');
});

/*
 * CLR-2 — withdrawing takes the office sheet off the filing, and keeps the words.
 *
 * Applying inserts that clearance's sheet into the wizard as a mandatory step
 * (`selectedOfficeCodes` is derived from the rows whose state is `applied`), and
 * MARKET's sheet requires a market name and a stall number. With no way to
 * withdraw, five real drafts carrying MARKET without a MARKET sheet could not
 * reach Review & Submit at all — the applicant had to invent a market they do
 * not trade from, or cancel the whole filing.
 *
 * Two halves, and the second is why withdrawing needs no confirmation: the step
 * goes (the row is no longer `applied`), and the saved answers do not.
 */
it('drops the office form step when a clearance is withdrawn, without discarding the answers', function () {
    $app = draftClearanceApplication();
    $market = PermitType::where('code', 'MARKET')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/MARKET/apply")->assertOk();
    $this->putJson("/api/v1/applications/{$app->id}/office-forms/MARKET", [
        'form_data' => ['market_name' => 'Malabon Central Market', 'stall_no' => 'B-14'],
    ])->assertOk();

    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/MARKET/apply")
        ->assertOk()->json();

    // No longer `applied`, so the wizard spawns no Market Clearance step and
    // Review & Submit is reachable again.
    expect($body['data']['state'])->toBe('available')
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('MARKET');

    $saved = ApplicationOfficeForm::where('application_id', $app->id)
        ->where('permit_type_id', $market->id)->first();

    expect($saved)->not->toBeNull()
        ->and($saved->form_data['stall_no'])->toBe('B-14');

    // Re-applying finds them still there — one click back, nothing retyped.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/MARKET/apply")->assertOk();
    expect(ApplicationOfficeForm::where('application_id', $app->id)
        ->where('permit_type_id', $market->id)->count())->toBe(1);
});

// --- issuance is no longer gated on money ------------------------------------

/*
 * There were four tests here holding a permit hostage to a balance: two on
 * `approveAndIssue` throwing, one on the officer getting a 422 at the counter,
 * and one on the permit being released the moment a second payment cleared.
 *
 * All four described a state that can no longer exist. A filing cannot reach
 * an officer's queue until its one Tax Order of Payment has been settled, and
 * nothing chargeable can be added to it after that — so a balance at approval
 * time is not a case to guard against, it is a contradiction. What replaces
 * them is the test below: a filing with clearances on it, paid for once, is
 * issued when its offices sign off and nothing asks for money again.
 */
/**
 * A paid filing carrying the business permit plus ZONING, taken as far as the
 * offices can take it: every assignment approved, nothing inspected yet.
 *
 * The office accounts are picked by department because that is the only way a
 * sign-off happens — ApplicationVisibility keeps a reviewer to the filings
 * routed to their own office, so BPLO cannot close CPDO's assignment.
 */
function clearanceFilingAwaitingInspection(): Application
{
    $app = draftClearanceApplication();
    test()->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    test()->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();
    test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    // Confirmed on receipt, because no office may approve until a person has
    // put their name to the processing category. What is under test here is the
    // clearance stage, so that gate belongs to the fixture and not to the case.
    classifyAsOfficer($app);

    foreach (ApplicationAssignment::where('application_id', $app->id)->get() as $assignment) {
        authAs($assignment->department_id === Department::where('code', 'CPDO')->first()->id
            ? 'zoning@biztrack.local'
            : 'bplo@biztrack.local');
        test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
    }

    return Application::findOrFail($app->id);
}

/**
 * Drive every visit booked against a filing to a pass, through the endpoint an
 * officer actually uses.
 *
 * THE RULE THIS ENCODES: all six supporting clearances — SANITARY, FSIC,
 * OCCUPANCY, CEC, ZONING, MARKET — carry `requires_inspection`, and only
 * BUSINESS does not. So a filing that asks for any clearance does NOT get its
 * permits when the last office signs off; WorkflowService::afterReviewProgress
 * books the visits and parks it in `for_inspection`, and recordInspection is
 * the only thing that can then issue, once EVERY visit has passed.
 *
 * The account is chosen by the inspecting office on purpose, and it is load
 * bearing twice over. `inspections/{id}/conduct` sits behind
 * `permission:inspection.manage`, and InspectionController scopes every read
 * and write to the caller's own department — so a visit booked for CPDO can
 * only be closed by a CPDO officer holding that permission. A 403 out of here
 * means an office was routed an inspection it cannot conduct, which is the
 * precise failure the client reported of OBO, CENRO, Market and Zoning before
 * RbacSeeder put `inspection.manage` on all six.
 */
function passEveryScheduledInspection(Application $app): void
{
    $officerFor = [
        'CHO' => 'sanitary@biztrack.local',
        'BFP' => 'fire@biztrack.local',
        'CPDO' => 'zoning@biztrack.local',
        'OBO' => 'obo@biztrack.local',
        'CENRO' => 'cenro@biztrack.local',
        'CMO-MARKET' => 'market@biztrack.local',
    ];

    $inspections = $app->inspections()->with('department')->get();

    // An empty loop would let this helper "pass" a filing that was never booked
    // an inspection at all, which is how these tests would silently stop
    // covering the inspected path if the flag were ever flipped back.
    expect($inspections)->not->toBeEmpty();

    foreach ($inspections as $inspection) {
        authAs($officerFor[$inspection->department->code]);
        test()->postJson("/api/v1/inspections/{$inspection->id}/conduct", [
            'result' => 'passed',
            'findings' => 'Premises inspected and found compliant.',
        ])->assertOk();
    }
}

it('issues every permit once the offices sign off, with no balance to settle', function () {
    $app = clearanceFilingAwaitingInspection();

    /*
     * The sign-offs alone no longer issue anything, and that is the rule rather
     * than an accident of this fixture: ZONING is inspected, so the filing waits
     * for the site visit. Asserting the intermediate state means a change that
     * skipped straight to `approved` would be caught here rather than quietly
     * making the rest of the test pass for the wrong reason.
     */
    expect($app->status)->toBe(ApplicationStatus::ForInspection)
        ->and($app->permits()->count())->toBe(0);

    passEveryScheduledInspection($app);

    $settled = Application::findOrFail($app->id);

    // Both permits: the business permit and the zoning clearance applied for.
    expect($settled->permits()->count())->toBe(2)
        ->and($settled->status)->toBe(ApplicationStatus::Approved)
        ->and(PermitFees::balance($settled)['balance_due'])->toBe(0.0);
});

it('reports a clearance as issued once its permit exists', function () {
    $app = clearanceFilingAwaitingInspection();

    /*
     * Before the visit the clearance is still only `applied` — the office has
     * agreed to it on paper, but a locational clearance is a statement about a
     * site and the site has not been looked at. `issued` is a claim that the
     * permit row exists, so it must not appear until the inspection releases it.
     */
    authAs('owner@biztrack.local');
    $beforeVisit = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'ZONING');
    expect($beforeVisit['state'])->toBe('applied');

    passEveryScheduledInspection($app);

    authAs('owner@biztrack.local');
    $row = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'ZONING');

    expect($row['state'])->toBe('issued');
});

// --- authorization -----------------------------------------------------------

it('refuses a stranger the clearance list', function () {
    $app = draftClearanceApplication();

    // A different business owner, holding a perfectly valid session.
    authAs('juan@biztrack.local');
    $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertForbidden();
});

it('refuses a stranger every clearance write', function () {
    $app = draftClearanceApplication();

    authAs('juan@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertForbidden();
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertForbidden();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertForbidden();
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/held")->assertForbidden();

    // The 403 is the whole story: nothing was attached, charged or stored.
    expect($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0);
});

it('refuses an officer the clearance chooser even on a filing its office reviews', function () {
    $app = draftClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    // The sanitary officer is routed this filing and may read the application
    // itself — but which clearances a business asks for is the applicant's
    // decision, and the chooser is not part of the review.
    authAs('sanitary@biztrack.local');
    $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertForbidden();
});

// --- guards ------------------------------------------------------------------

it('has no clearance endpoint for the permit the application is for', function () {
    $app = draftClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/BUSINESS/apply")->assertNotFound();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/NOT_A_CODE/apply")->assertNotFound();
});

it('survives a filing whose business has been removed from the register', function () {
    $app = draftClearanceApplication();
    $app->business->delete();

    $body = $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json();

    // The stage still renders; only the price it cannot compute is withheld.
    expect($body['data'])->toHaveCount(6)
        ->and($body['data'][0]['fee_preview'])->toBeNull()
        ->and($body['meta']['unlocked'])->toBeTrue();

    // And both writes that need a price say so, rather than letting the
    // applicant agree to a charge nobody can quote.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertStatus(422);
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertStatus(422);
});

it('still lets a held copy be filed when the business record has gone', function () {
    $app = draftClearanceApplication();
    $app->business->delete();

    // Nothing here needs a price — the applicant can still hand in the
    // certificate they hold.
    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe('submitted');

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")->assertOk();
});

it('reports an office form as complete only once the applicant has saved it', function () {
    $app = draftClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    $row = fn () => collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'SANITARY');

    expect($row()['office_form_complete'])->toBeFalse();

    $this->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
        'form_data' => ['owner_name' => 'Ana Dela Cruz'],
    ])->assertOk();

    expect($row()['office_form_complete'])->toBeTrue();
});

it('refuses a payment when the filing owes nothing', function () {
    $app = submittedClearanceApplication('Nothing Owed Cafe');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'This application has nothing outstanding.');
});

it('opens the stage for the service, and only for a draft', function () {
    $service = app(ClearanceService::class);
    $app = draftClearanceApplication('Service Level Cafe');

    expect($service->isUnlocked($app))->toBeTrue()
        ->and($service->lockedReason($app))->toBeNull();

    $this->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    $submitted = $app->fresh();
    expect($service->isUnlocked($submitted))->toBeFalse()
        ->and($service->lockedReason($submitted))->toBeString();
});
