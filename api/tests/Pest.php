<?php

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationDocument;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\Ra11032;
use App\Support\RequiredDocuments;
use App\Support\SheetRequirements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Feature tests hit the full app + a fresh, seeded database.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Register rows with chosen timestamps, for the analytics tests.
require_once __DIR__.'/AnalyticsFixtures.php';

/**
 * Log in a seeded demo account and return its bearer token.
 *
 * The three portals sign in through separate doors, so the portal is inferred
 * from the account's roles unless a caller pins it deliberately (which is how
 * the wrong-door rejection gets tested).
 */
function loginToken(string $email, string $password = 'biztrack1', ?string $portal = null): string
{
    $res = test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
        'portal' => $portal ?? portalFor($email),
    ]);
    $res->assertOk();

    return $res->json('data.token');
}

/**
 * Which sign-in door a seeded account belongs to.
 *
 * Three answers since item #107 gave the super admin its own door. `admin` is
 * tested first and by name: it used to fall into the staff branch below along
 * with the six offices, and a helper that still said 'staff' for it would fail
 * every admin-driven test on the wrong-door 409 — a failure that reads as a
 * permission problem and is nothing of the kind.
 */
/**
 * Route a filing to an office, and answer with that office's id.
 *
 * This lived in ConversationSeparationTest until MessageThreadsTest needed the
 * same thing. A Pest run filtered to one file loads only that file, so a
 * fixture two suites share has to be in the shared home rather than in
 * whichever suite happened to write it first - and two copies would redeclare
 * the function on a full run.
 *
 * It matters more than it used to. An office's Messages page is its caseload
 * now [client, 28 September 2026], so a fixture that wants an office to SEE a
 * filing has to hand it to them; before this, writing to them was enough.
 */
function assignOffice(int $applicationId, string $departmentCode): int
{
    $department = Department::where('code', $departmentCode)->firstOrFail();

    ApplicationAssignment::firstOrCreate([
        'application_id' => $applicationId,
        'department_id' => $department->id,
    ]);

    return $department->id;
}

/**
 * Route a filing to an office AND have that office's seeded officer take it,
 * answering with the office's id.
 *
 * An owner may message an office about a filing only once an officer there
 * holds it (checklist 2026-09-27, apply item 23), so a fixture in which the
 * owner writes first has to put somebody on the case. assignOffice() alone
 * leaves the queue unclaimed, which is the state the owner is refused in.
 */
function takeFiling(int $applicationId, string $departmentCode): int
{
    $departmentId = assignOffice($applicationId, $departmentCode);
    $officer = User::where('department_id', $departmentId)
        ->where('is_active', true)
        ->orderBy('id')
        ->firstOrFail();

    ApplicationAssignment::where('application_id', $applicationId)
        ->where('department_id', $departmentId)
        ->update(['officer_user_id' => $officer->id, 'assigned_at' => now()]);

    return $departmentId;
}

/**
 * Have an officer press "Assign to Me" on their office's case on a filing.
 *
 * ── Why every fixture that works a case now calls this ─────────────────────
 *
 * Request of 6 October 2026, for every office except BPLO: "The officer must
 * click 'Assign to Me' before they can access and process the application."
 * Before it, acting on an unheld case claimed it on the way past, so a fixture
 * could log an officer in and press Approve. Since it, an unheld CHO, BFP,
 * CPDO, OBO or CENRO case refuses to be opened or worked
 * (`App\Support\CaseHolder::UNCLAIMED`), and roughly a hundred and fifty tests
 * that were about something else entirely — re-inspection, fees, requests —
 * started failing on that 403 at their first officer action.
 *
 * They are modelling an officer who has done what the screen asks, so they do
 * it too, through the real endpoint: the claim runs, and this asserts it was
 * accepted. Claiming is the subject of ClaimBeforeWorkTest and
 * OfficerInChargeTest, not of the callers of this.
 *
 * It is the SAME account that then acts, because only the holder may act — a
 * fixture that claimed as one officer and approved as another would be
 * refused with `CaseHolder::HELD_ELSEWHERE` instead.
 *
 * `$departmentCode` names the office when the account is not the one doing the
 * claiming's department — left null, it is the account's own office, which is
 * the only office it can claim for anyway. The office's FIRST case on the
 * filing by id is claimed, which is the one `CaseHolder::caseOf` judges.
 *
 * Side effect, on purpose and worth knowing: the test client is left signed in
 * as `$email`, because the caller is about to act as that officer. A fixture
 * that goes on to act as somebody else calls `authAs` for them, as it already
 * had to.
 *
 * Harmless on a BPLO case, where it is the explicit form of the claim that
 * acting would have made anyway, and on a case this officer already holds,
 * where the endpoint answers 200 and changes nothing.
 */
function claimAs(string $email, int $applicationId, ?string $departmentCode = null): int
{
    $user = User::where('email', $email)->firstOrFail();
    $departmentId = $departmentCode !== null
        ? Department::where('code', $departmentCode)->value('id')
        : $user->department_id;

    $assignmentId = ApplicationAssignment::where('application_id', $applicationId)
        ->where('department_id', $departmentId)
        ->orderBy('id')
        ->value('id');

    // A missing assignment is a fixture bug — the office was never routed —
    // and should say so here, not as a 403 three lines further on.
    expect($assignmentId)->not->toBeNull("No {$departmentCode} assignment on filing {$applicationId} to claim.");

    authAs($email);
    test()->postJson("/api/v1/assignments/{$assignmentId}/claim")->assertOk();

    return $assignmentId;
}

/**
 * The seeded officer of an office, by office code (DemoSeeder seats one each).
 *
 * For fixtures that route a filing by office code and must then have that
 * office's officer claim it (`claimAs`): the claim has to be made by the
 * account that will act, and these are the accounts the suites act as.
 */
function officerEmail(string $departmentCode): string
{
    return match ($departmentCode) {
        'BPLO' => 'bplo@biztrack.local',
        'CHO' => 'sanitary@biztrack.local',
        'BFP' => 'fire@biztrack.local',
        'CPDO' => 'zoning@biztrack.local',
        'OBO' => 'obo@biztrack.local',
        'CENRO' => 'cenro@biztrack.local',
    };
}

/**
 * Move the clock FORWARD to the next weekday at 10:00 AM, Manila.
 *
 * An office books a visit only on a weekday between 8:00 AM and 5:00 PM
 * (checklist 2026-09-27, manage item 4), and the inspection suites book for
 * "today" so the result can be recorded straight away. Run at 10 PM or on a
 * Sunday they would be refused, so they run in office hours instead.
 *
 * Forward and never back, because the seeded rows were written at the real
 * time and a clock behind them would make "now" older than the register.
 */
function duringOfficeHours(): void
{
    $now = CarbonImmutable::now(config('app.timezone'));
    $at = $now->setTime(10, 0);
    if ($at->lessThanOrEqualTo($now)) {
        $at = $at->addDay();
    }
    while ($at->isWeekend()) {
        $at = $at->addDay();
    }

    test()->travelTo($at);
}

function portalFor(string $email): string
{
    $user = User::where('email', $email)->first();

    if (! $user) {
        return 'public';
    }

    $roles = $user->roles->pluck('name');

    if ($roles->contains('admin')) {
        return 'admin';
    }

    return $roles->contains(fn ($r) => $r !== 'business_owner') ? 'staff' : 'public';
}

/**
 * Authenticate the test client as a seeded demo account via Sanctum.
 * Returns an empty header array so existing `withHeaders(authAs(...))` calls
 * keep working; the guard is what actually carries the identity, and it is
 * reset on each call so switching accounts mid-test is reliable.
 */
function authAs(string $email, string $password = 'biztrack1'): array
{
    $user = User::where('email', $email)->firstOrFail();

    // Reset any previously-resolved guard user, then act as this one so that
    // switching accounts mid-test is reliable.
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);

    return [];
}

/**
 * Put an office's name on a filing's RA 11032 processing category.
 *
 * This is a PRECONDITION, not a subject. WorkflowService refuses to approve a
 * filing nobody has categorised — `complexity` alone is not enough, because
 * submit() seeds a guess from Ra11032::tierFor() and the gate asks who chose,
 * not whether a value is present (see requireProcessingCategory). A filing that
 * has been paid for and routed to its offices has, in real use, been read by a
 * clerk who confirmed the category on the review sheet before anyone pressed
 * Approve; every fixture that drives a filing to issuance is modelling that
 * office, so it has to perform that step too.
 *
 * The default tier is the one already on the filing — confirming the guess
 * rather than overruling it. That is both the commonest real action and the one
 * that changes nothing else: the deadline is recomputed to the value it already
 * held, so a fixture using this cannot quietly move a statutory clock out from
 * under the test that follows.
 *
 * Called at the service rather than through POST /assignments/{id}/classification
 * so it stays one line and does not disturb the acting user; the endpoint and
 * its authorization are the subject of Ra11032ClassificationTest.
 */
/**
 * The ids of all five other permits — BPLO's ticks for a fixture that calls
 * `approveMainForm` directly on a NEW filing carrying none (since 5 October
 * 2026, see `bploApprovesForm`).
 *
 * @return list<int>
 */
function allOtherPermitIds(): array
{
    return PermitType::whereIn('code', PermitType::REQUIRED_CLEARANCE_CODES)->pluck('id')->all();
}

/**
 * BPLO accepts the main form, which is what raises the bill.
 *
 * A PRECONDITION of paying, and since 6 September 2026 it is not an optional
 * one. The verified counter procedure is submit → For Approval → BPLO approves →
 * Pending Payment → pay, and `ApplicationStatus::isBillable()` enforces it: a
 * POST to `/pay` at `for_approval` is refused with "BPLO has not approved this
 * application yet".
 *
 * Thirteen fixtures across this suite went submit-then-pay on two consecutive
 * lines, because until that date nothing sat between them. They share this
 * rather than each growing its own copy — the sequence is one fact about the
 * process, and the last time it was spread by hand it had to be corrected in
 * every file at once.
 *
 * Driven at the service rather than through POST /assignments/{id}/approve so it
 * stays one line and does not disturb the acting user, which is the convention
 * `classifyAsOfficer` below documents and this depends on: the workflow refuses
 * to approve a filing nobody has categorised.
 *
 * ── The other permits, on a NEW filing ───────────────────────────────────
 *
 * Since 5 October 2026 a new filing is submitted with the Business Permit
 * alone and BPLO ticks its other permits here (client: "BPLO decides, no
 * rules"); approving one that carries none, with none ticked, is refused.
 * `$codes` are those ticks. Left null, a NEW filing that carries no clearance
 * yet is given all five — what every fixture written before that date
 * assumed it carried — and one that already carries some (created with an
 * explicit `permit_type_ids`) keeps them, as the product does.
 *
 * @param  list<string>|null  $codes
 */
function bploApprovesForm(Application|int $app, ?array $codes = null): Application
{
    // An id is accepted because most fixtures hold one, not a model, and making
    // twelve files import Application to call one helper is a worse trade than
    // one lookup here.
    $app = $app instanceof Application ? $app : Application::findOrFail($app);

    if ($codes === null
        && $app->application_type === ApplicationType::New
        && ! $app->permitTypes()->whereIn('permit_types.code', PermitType::REQUIRED_CLEARANCE_CODES)->exists()) {
        $codes = PermitType::REQUIRED_CLEARANCE_CODES;
    }

    classifyAsOfficer($app);
    app(WorkflowService::class)->approveMainForm(
        $app->fresh(),
        null,
        $codes === null ? null : PermitType::whereIn('code', $codes)->pluck('id')->all(),
    );

    return $app->fresh();
}

/**
 * How many OTHER permits this filing has released — never the business permit.
 *
 * ── Why this exists, and why it is not `permits()->count()` ──────────────────
 *
 * Fourteen assertions across seven files counted every permit on a filing and
 * meant "how many of the five clearances have their offices released". The two
 * were the same number until 24 September 2026, because the Mayor's Permit was
 * minted last, by `approveOverall()`, once all five were in.
 *
 * The LGU moved the release to PAYMENT — *"after payment, business permit is
 * already released"* — so the business permit is now present from the moment
 * the filing is paid, and every one of those counts reads one higher than the
 * thing it was checking. Adding 1 to each literal would have made them pass
 * while quietly turning a statement about clearances into arithmetic nobody
 * could read.
 *
 * So the question is asked directly. A test that genuinely wants the business
 * permit asks for it by name — see `PermitReleasedAtPaymentTest`, which is
 * where that certificate's own behaviour is pinned.
 */
function clearancePermitsIssued(Application|int $app): int
{
    $id = $app instanceof Application ? $app->id : $app;

    return Permit::where('application_id', $id)
        ->whereHas('permitType', fn ($q) => $q->where('code', '!=', PermitType::OUTCOME_CODE))
        ->count();
}

function classifyAsOfficer(Application $app, string $email = 'bplo@biztrack.local', ?string $tier = null): Application
{
    $app = $app->fresh();

    return app(WorkflowService::class)->classify(
        $app,
        $tier ?? $app->complexity ?? Ra11032::tierFor($app),
        User::where('email', $email)->firstOrFail(),
    );
}

/** A paid, routed filing carrying BUSINESS + SANITARY (BPLO + CHO). */
function scopedAssignmentFiling(string $name): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '3 Scoped Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'SANITARY'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    /*
     * And the applicant opens SANITARY and hands its sheet in, which is what
     * reaches CHO.
     *
     * Paying no longer routes anybody but BPLO. Under
     * docs/application-flow-2026-09.md the clearance stage opens on payment and
     * each office is handed the filing when the owner submits that office's
     * permit, so without these two calls CHO has no assignment and every case
     * below is arguing about a row that does not exist.
     *
     * Two calls because SANITARY carries a form. Apply OPENS the sheet and
     * stops — it used to announce "For Approval" on a form nobody had filled in
     * (client, 9 September 2026) — and it is saving the sheet with `submit`
     * that hands it over and creates the assignment. The answers travel in the
     * same write on purpose: `ownerMayEdit` closes the sheet the instant it is
     * submitted, so apply → fill → submit is the only order left, and one PUT
     * is that order.
     */
    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/clearances/SANITARY/apply")
        ->assertSuccessful();

    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$appId}/office-forms/SANITARY", [
            'form_data' => ['sanitary_classification' => 'Food Establishment'],
            'submit' => true,
        ])->assertSuccessful();

    return $appId;
}

/**
 * `scopedAssignmentFiling`, with CHO's officer having pressed Assign to Me.
 *
 * For the cases that go on to WORK the CHO review as sanitary@ — approve,
 * book, conduct. Since the request of 6 October 2026 an unheld CHO case
 * refuses all of that (CaseHolder::UNCLAIMED), so those cases need the claim
 * and the rest need it NOT made: OfficerInChargeTest claims and releases the
 * bare fixture itself, which is why this is a second helper rather than a
 * change to the first. Leaves sanitary@ signed in (see claimAs).
 */
function scopedFilingHeldByCho(string $name): int
{
    $appId = scopedAssignmentFiling($name);
    claimAs('sanitary@biztrack.local', $appId);

    return $appId;
}

/** CHO's assignment on this filing. */
function choAssignmentId(int $applicationId): int
{
    return ApplicationAssignment::where('application_id', $applicationId)
        ->whereHas('department', fn ($d) => $d->where('code', 'CHO'))
        ->value('id');
}

/*
 * ── Fixtures for the returned-fields work, 27 September 2026 ───────────
 *
 * Here rather than in the test files that first needed them, because a
 * helper declared inside a test file does not exist until that file loads:
 * a second file reusing it passes in a whole-suite run and fails the moment
 * anyone runs that second file alone.
 */

/** A new filing for a business with no TIN, sitting at For Approval. */
function filingWithoutTin(string $tin = ''): Application
{
    $owner = authAs('owner@biztrack.local');

    $payload = [
        'name' => 'No TIN Trading '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'address' => ['line1' => '9 Blank Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ];
    if ($tin !== '') {
        $payload['tin'] = $tin;
    }

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', $payload)
        ->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId)->fresh();
}

/** A filing returned by BPLO about the given field codes. */
function filingReturnedAbout(string $targets): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Returned Fields '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'trade_name' => 'Old Trade Name',
        'address' => ['line1' => '3 Correction Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 300000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $app = Application::findOrFail($appId);
    app(WorkflowService::class)->returnMainForm($app, 'Please correct these.', $targets);

    return $app->fresh();
}

/**
 * Give a filing every document its office checklist asks for.
 *
 * Since 30 September 2026 `WorkflowService::submitClearanceForm` refuses a
 * sheet whose checklist is not complete, on the client's instruction that the
 * documentary requirements are required. A test about something else entirely
 * — routing, fees, inspection order — still has to get the sheet in, and the
 * factories build filings with no attachments at all.
 *
 * Production filings do not look like that: the wizard will not let a business
 * permit application be submitted without its required documents, so by the
 * time the applicant reaches an office sheet the carried rows are already
 * answered. This is the test fixture catching up with that, not a way around
 * the rule — the rule runs, and these documents satisfy it.
 *
 * Two kinds of row are filled. An `upload` row has a slot of its own, named by
 * `code`. A `carried` row is answered by a business-permit attachment, named
 * by `carried_from`. A `sheet` row is the form itself and never blocks. A row
 * answered by a PERMIT rather than a document — CENRO's previous-year CEC — is
 * not something a file can satisfy, so a test that needs it issues the permit.
 */
function satisfyChecklist(Application $application, PermitType|string $type): Application
{
    $code = $type instanceof PermitType ? $type->code : $type;

    foreach (SheetRequirements::for($application, $code) ?? [] as $row) {
        if (($row['blocking'] ?? false) !== true || ($row['satisfied'] ?? false) === true) {
            continue;
        }

        $documentCode = $row['code'] ?? $row['carried_from'] ?? null;
        if ($documentCode === null) {
            continue;
        }

        $typeId = $row['code'] !== null
            // An upload slot's document type is made on demand, exactly as the
            // upload endpoint makes it.
            ? SheetRequirements::documentType($code, $row['code'])->id
            : DocumentType::where('code', $documentCode)->value('id');

        if ($typeId === null) {
            continue;
        }

        ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => $typeId,
            'original_filename' => strtolower($documentCode).'.pdf',
            'stored_path' => 'private/documents/test/'.strtolower($documentCode).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
    }

    return $application->fresh();
}

/**
 * A complete home address, as registration has required of every business
 * owner since 28 September 2026 [checklist Register 2]. Spread into a
 * registration payload; override a part to test it.
 *
 * @return array<string, string>
 */
function homeAddress(array $overrides = []): array
{
    return array_merge([
        'home_street' => '12 Gen. Luna St.',
        'home_barangay' => 'Longos',
        'home_city' => 'Malabon',
        'home_province' => 'Metro Manila',
        'home_postal_code' => '1472',
    ], $overrides);
}

/**
 * Upload every Documentary Requirement this filing must carry to submit.
 *
 * `ApplicationController::submit` refuses a filing missing a required document
 * since 5 October 2026 (`App\Support\RequiredDocuments`), and a fixture that
 * submits is modelling an applicant who went through the Documents step. Like
 * `satisfyChecklist`, the rule runs and these rows satisfy it — the list is
 * asked of the same class the gate asks, so a fixture cannot drift from it.
 *
 * Call it AFTER the filing's permit types, business answers and (for an
 * amendment) requested changes are in place: the list depends on them.
 */
function attachRequiredDocuments(Application|int $application): ?Application
{
    // A test submitting an id that does not exist is testing the 404.
    $application = $application instanceof Application ? $application : Application::find($application);
    if ($application === null) {
        return null;
    }

    foreach (RequiredDocuments::missingFor($application) as $type) {
        ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'original_filename' => strtolower($type->code).'.pdf',
            'stored_path' => 'private/documents/test/'.strtolower($type->code).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
    }

    return $application->fresh();
}
