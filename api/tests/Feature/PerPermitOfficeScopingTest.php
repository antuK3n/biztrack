<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Office separability, applied to the per-permit machine (client, 2026-09-06:
 * "the City Health Office admin must NOT see any application fields regarding
 * Fire Safety Inspection Certificate application and any other offices'
 * application forms").
 *
 * The rule itself is old and already stated four times over in
 * `ApplicationVisibility` — office forms, issued permits, message threads and
 * inspection findings. What is new is that a filing now carries a STATUS, a
 * MODE and an office's REMARKS per permit, and every one of those is a field
 * that did not exist when the boundary was last audited.
 *
 * ── The shape of the bug this file exists to catch ────────────────────────
 *
 * Three times now the same defect has been found and fixed one door at a time:
 * the rule was enforced in the controller that owned the endpoint, and the
 * officer's review sheet reached the same data through
 * `GET /assignments/{id}` → ApplicationResource, which had no filter at all.
 * Same user, same filing, two endpoints, two answers.
 *
 * So these tests deliberately read through the REVIEW SHEET's door rather than
 * through the resource-specific one. A pass here and a 403 on
 * `GET /permits/{id}` is what "fixed" looked like the last three times, and it
 * was not fixed.
 *
 * ── What is withheld and what is not ──────────────────────────────────────
 *
 * The line is the one `readsInspectionDetail` already draws, and it is drawn in
 * the same place for the same reason. Bare progress is SHARED: an office
 * waiting on the filing has a genuine need to know that the fire permit exists,
 * that it reached inspection, and that it passed — BPLO's final approval is
 * gated on all five, so hiding a permit's state would replace a privacy defect
 * with a coordination one. What is withheld is the free prose about someone
 * else's filing: an office's remarks and its reason for refusing.
 */

/** A paid filing with every permit attached, ready for the offices to work. */
function paidFilingForScoping(): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);

    $workflow = app(WorkflowService::class);
    $workflow->submit($app);
    $app->refresh();

    classifyAsOfficer($app);
    $workflow->approveMainForm($app->fresh());
    $app->refresh();

    // Straight to the paid state. The payment itself is exercised elsewhere;
    // what this file is about is what each office can read afterwards.
    $workflow->transition($app, ApplicationStatus::AwaitingOtherPermits, 'Paid.');

    return $app->fresh();
}

/** Start one permit and put the issuing office's own words on it. */
function officeWorksPermit(Application $app, string $code, string $remarks): ApplicationPermitType
{
    $type = PermitType::where('code', $code)->firstOrFail();
    app(WorkflowService::class)->startClearance($app, $type, ApplicationPermitType::MODE_APPLY);

    $row = ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', $type->id)
        ->firstOrFail();
    $row->update(['remarks' => $remarks]);

    return $row->fresh();
}

it('does not show one office the remarks another office wrote on its own permit', function () {
    $app = paidFilingForScoping();
    officeWorksPermit($app, 'FSIC', 'BFP: exit signage is not illuminated on the second floor.');
    officeWorksPermit($app, 'SANITARY', 'CHO: food handlers’ health certificates are current.');

    $payload = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.permit_types');

    $fsic = collect($payload)->firstWhere('code', 'FSIC');
    $sanitary = collect($payload)->firstWhere('code', 'SANITARY');

    expect($fsic)->not->toBeNull('the fire permit vanished from the filing entirely');

    // Bare progress is shared, deliberately — see the header.
    expect($fsic['status'])->toBe(ClearanceStatus::ForApproval->value)
        ->and($fsic['is_required'])->toBeTrue();

    // The prose is not.
    expect($fsic['remarks'])->toBeNull('CHO read the fire office’s remarks');

    // And the office's own remarks are still its own to read.
    expect($sanitary['remarks'])->toContain('food handlers');
});

it('does not show one office another office’s reason for refusing a permit', function () {
    $app = paidFilingForScoping();
    $fsicRow = officeWorksPermit($app, 'FSIC', 'BFP working it.');
    officeWorksPermit($app, 'SANITARY', 'CHO working it.');

    app(WorkflowService::class)->rejectClearance(
        $fsicRow,
        'The premises has no second means of egress.',
    );

    $payload = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.permit_types');

    $fsic = collect($payload)->firstWhere('code', 'FSIC');

    // That it was refused is coordination: the filing cannot reach BPLO's final
    // approval while it stands, and every office on it is waiting on that.
    expect($fsic['status'])->toBe(ClearanceStatus::Rejected->value);

    // Why it was refused is the fire office's business with the applicant.
    expect($fsic['rejection_reason'])
        ->toBeNull('CHO read the fire office’s reason for refusing');
});

it('does not hand one office a permit certificate issued by another', function () {
    $app = paidFilingForScoping();
    $fsicRow = officeWorksPermit($app, 'FSIC', 'BFP working it.');
    officeWorksPermit($app, 'SANITARY', 'CHO working it.');

    // Fire finishes: paperwork approved, visit booked, visit passed, permit out.
    $workflow = app(WorkflowService::class);
    $workflow->approveClearance($fsicRow->fresh());
    $visit = $workflow->scheduleClearanceInspection($fsicRow->fresh(), now()->addWeekdays(2));
    $workflow->recordInspection($visit, InspectionResult::Passed, 'All clear.');

    expect($app->fresh()->permits()->count())->toBe(1, 'the fire permit was not issued');

    $permits = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.permits');

    expect(collect($permits)->pluck('permit_type.code')->all())
        // CHO was handed a BFP-issued certificate through the review sheet.
        ->not->toContain('FSIC');
});

it('does not show one office another office’s review remarks on its assignment', function () {
    $app = paidFilingForScoping();
    $fsicRow = officeWorksPermit($app, 'FSIC', 'BFP working it.');
    officeWorksPermit($app, 'SANITARY', 'CHO working it.');

    app(WorkflowService::class)->approveClearance($fsicRow->fresh(), 'BFP: signed off by Insp. Reyes.');

    $assignments = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.assignments');

    $bfp = collect($assignments)->first(fn ($a) => ($a['department']['code'] ?? null) === 'BFP');
    expect($bfp)->not->toBeNull('the fire office vanished from the filing entirely');

    // Progress shared, prose and the named officer withheld — the same split
    // INS-8 settled for inspections.
    expect($bfp['status'])->toBe('completed');
    expect($bfp['remarks'])->toBeNull('CHO read the fire office’s review remarks');
    expect($bfp['officer'])->toBeNull('CHO was told which fire officer signed it off');
});

it('still lets BPLO and the super admin read every office’s words', function () {
    $app = paidFilingForScoping();
    officeWorksPermit($app, 'FSIC', 'BFP: exit signage is not illuminated.');

    foreach (['bplo@biztrack.local', 'admin@biztrack.local'] as $email) {
        $payload = test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$app->id}")
            ->assertOk()
            ->json('data.permit_types');

        $fsic = collect($payload)->firstWhere('code', 'FSIC');
        expect($fsic['remarks'])->toContain('exit signage');
    }
});

it('still lets the applicant read every word written on their own filing', function () {
    $app = paidFilingForScoping();
    officeWorksPermit($app, 'FSIC', 'BFP: exit signage is not illuminated.');

    $payload = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.permit_types');

    /*
     * The applicant is the person who has to PUT THIS RIGHT. Withholding an
     * office's remarks from them would be the privacy rule eating the product:
     * they cannot fix exit signage nobody told them about.
     */
    expect(collect($payload)->firstWhere('code', 'FSIC')['remarks'])
        ->toContain('exit signage');
});

/*
 * ── The held-permit copy, which is half of the new flow ────────────────────
 *
 * An applicant satisfies each of the five permits either by filling that
 * office's form or by handing in the permit they already hold
 * (docs/application-flow-2026-09.md rule 3). The upload is stored as an
 * `application_documents` row carrying `permit_type_id`, so a held Fire Safety
 * Inspection Certificate is a document that belongs to BFP as squarely as the
 * FSIC questionnaire does.
 *
 * Documents were scoped at the FILING level and no finer, which was right while
 * every attachment was a shared requirement — a barangay clearance really is
 * every office's business. It stopped being enough the moment half the evidence
 * on a filing became office-specific.
 */
it('does not show one office a permit copy handed in for another office', function () {
    Storage::fake('local');

    $app = paidFilingForScoping();

    // CHO has to be ON the filing, or this proves only that a stranger is kept
    // out — which is `canView`'s job and a different rule.
    officeWorksPermit($app, 'SANITARY', 'CHO working it.');

    /*
     * Through the clearance stage, which is the only door under this flow. The
     * direct `POST /documents` path with `permit_type_id` is Draft/Returned
     * only, and the stage does not open until the filing is paid — so the two
     * windows are now disjoint rather than overlapping.
     */
    authAs('owner@biztrack.local');
    $doc = test()->postJson("/api/v1/applications/{$app->id}/clearances/FSIC/held", [
        'file' => UploadedFile::fake()->create('our-fsic.pdf', 40, 'application/pdf'),
    ])->assertCreated()->json('data.held_document');

    $sanitary = authAs('sanitary@biztrack.local');

    $listed = test()->withHeaders($sanitary)
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.documents');

    expect(collect($listed)->pluck('id')->all())
        // CHO was listed a fire-office permit copy.
        ->not->toContain($doc['id']);

    // And the id is typeable, so the list is not the boundary.
    test()->withHeaders($sanitary)
        ->get("/api/v1/documents/{$doc['id']}/download")
        ->assertForbidden();
});

it('still shows every office the shared requirements the applicant uploaded', function () {
    Storage::fake('local');

    $app = paidFilingForScoping();
    officeWorksPermit($app, 'SANITARY', 'CHO working it.');

    authAs('owner@biztrack.local');
    $doc = test()->postJson("/api/v1/applications/{$app->id}/documents", [
        'document_type_id' => DocumentType::where('code', 'BRGY_CLEARANCE')->firstOrFail()->id,
        'file' => UploadedFile::fake()->create('brgy.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json('data');

    /*
     * The other half of the rule, and the one that would be easy to break while
     * fixing the first. A barangay clearance, a lease contract, a valid ID —
     * these are the APPLICANT's particulars and every office on the filing needs
     * them. `ApplicationVisibility::readsOfficeSheet` says so in as many words:
     * this is not a licence to strip the shared sheet.
     */
    $sanitary = authAs('sanitary@biztrack.local');

    $listed = test()->withHeaders($sanitary)
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.documents');

    expect(collect($listed)->pluck('id')->all())->toContain($doc['id']);

    test()->withHeaders($sanitary)
        ->get("/api/v1/documents/{$doc['id']}/download")
        ->assertOk();
});

it('does not show one office the questionnaire another office collected', function () {
    $app = paidFilingForScoping();
    officeWorksPermit($app, 'FSIC', 'BFP working it.');
    officeWorksPermit($app, 'SANITARY', 'CHO working it.');

    // The applicant answers both sheets, as they would in the clearance stage.
    authAs('owner@biztrack.local');
    foreach ([
        'FSIC' => ['storey_count' => '2', 'floor_area' => '180'],
        'SANITARY' => ['sanitary_classification' => 'Food Establishment'],
    ] as $code => $formData) {
        test()->putJson("/api/v1/applications/{$app->id}/office-forms/{$code}", [
            'form_data' => $formData,
        ])->assertSuccessful();
    }

    /*
     * Read through `GET /assignments/{id}`, not the application endpoint.
     *
     * That is the officer's review sheet, and it is the door SEP-1 was found
     * behind: `officeForms` is not eager-loaded on `GET /applications/{id}` at
     * all, so `office_forms` is always empty there and asserting against it
     * would prove nothing while looking like it proved everything.
     */
    $cho = User::where('email', 'sanitary@biztrack.local')->firstOrFail();
    $assignment = $app->assignments()->where('department_id', $cho->department_id)->firstOrFail();

    $sheets = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/assignments/{$assignment->id}")
        ->assertOk()
        ->json('data.application.office_forms');

    $codes = collect($sheets)->pluck('permit_type_code')->all();

    // CHO keeps its own questionnaire and never sees the fire office’s.
    expect($codes)->toContain('SANITARY')
        ->and($codes)->not->toContain('FSIC');
});
