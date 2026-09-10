<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\Ra11032;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Feature tests hit the full app + a fresh, seeded database.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Log in a seeded demo account and return its bearer token.
 *
 * Staff and business owners sign in through separate portals, so the portal is
 * inferred from the account's roles unless a caller pins it deliberately (which
 * is how the wrong-door rejection gets tested).
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

/** Which sign-in door a seeded account belongs to. */
function portalFor(string $email): string
{
    $user = User::where('email', $email)->first();

    return $user && $user->roles->pluck('name')->contains(fn ($r) => $r !== 'business_owner')
        ? 'staff'
        : 'public';
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
 */
function bploApprovesForm(Application|int $app): Application
{
    // An id is accepted because most fixtures hold one, not a model, and making
    // twelve files import Application to call one helper is a worse trade than
    // one lookup here.
    $app = $app instanceof Application ? $app : Application::findOrFail($app);

    classifyAsOfficer($app);
    app(WorkflowService::class)->approveMainForm($app->fresh());

    return $app->fresh();
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

/** CHO's assignment on this filing. */
function choAssignmentId(int $applicationId): int
{
    return ApplicationAssignment::where('application_id', $applicationId)
        ->whereHas('department', fn ($d) => $d->where('code', 'CHO'))
        ->value('id');
}
