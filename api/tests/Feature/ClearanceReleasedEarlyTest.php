<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\OfficerRequestStatus;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\Department;
use App\Models\OfficerRequest;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;

/*
 * Each permit is released the moment ITS office finishes, not when the last
 * office does.
 *
 * The client (9 September 2026): "if CEC is already approved by CENRO, the said
 * permit/certificate will now be displayed in the profile. It will not wait for
 * the other 4 permits to be approved before CEC is displayed."
 *
 * ── Why this is worth a test rather than a reading of the code ─────────────
 *
 * Because it used not to be true, and the way it used to be false is invisible
 * from any one screen. The old service minted every permit in one act —
 * `approveAndIssue()` ran when the LAST office signed off and wrote the whole
 * set at once — so an applicant whose sanitary permit was approved in January
 * saw nothing until the Building Official got round to them in March. Permits
 * are issued one at a time now (`grantClearance` → `issuePermitFor`), and
 * nothing on the Profile filters on the application's status.
 *
 * "Nothing filters on it" is exactly the kind of claim that stops being true
 * when somebody adds a sensible-looking `where('status', 'approved')` to the
 * permits query. So this drives one clearance to approved on a filing whose
 * other four are untouched, and reads the applicant's own permit list through
 * the API to prove the certificate reaches them.
 */

/** A paid filing with every permit attached and nothing yet worked. */
function filingAwaitingItsPermits(): Application
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
    $workflow->transition($app, ApplicationStatus::AwaitingOtherPermits, 'Paid.');

    return $app->fresh();
}

/** Take ONE clearance the whole way: applied, submitted, approved, inspected. */
function clearanceApproved(Application $app, string $code): ApplicationPermitType
{
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', $code)->firstOrFail();

    // Two acts: applying opens the office's form, saving it hands it in.
    $workflow->startClearance($app, $type, ApplicationPermitType::MODE_APPLY);
    $workflow->submitClearanceForm($app, $type);

    $row = ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', $type->id)
        ->firstOrFail();

    $workflow->approveClearance($row, 'Paperwork accepted.');
    $inspection = $workflow->scheduleClearanceInspection($row->fresh(), now()->addDay());
    $workflow->recordInspection($inspection, InspectionResult::Passed, 'Premises in order.');

    return $row->fresh();
}

it('issues a clearance’s certificate as soon as its own office is done', function () {
    $app = filingAwaitingItsPermits();
    $row = clearanceApproved($app, 'CEC');

    expect($row->status)->toBe(ClearanceStatus::Approved);

    $app->refresh()->load('permits.permitType', 'permitTypes');

    // The filing itself has NOT finished — four clearances are untouched.
    expect($app->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
    $outstanding = $app->permitTypes
        ->filter(fn (PermitType $pt) => $pt->isRequiredClearance())
        ->filter(fn (PermitType $pt) => $pt->pivot->status !== ClearanceStatus::Approved);
    expect($outstanding)->toHaveCount(4);

    // And the certificate exists anyway, for that one permit and no other.
    expect($app->permits->pluck('permitType.code')->all())->toBe(['CEC']);
    expect($app->permits->first()->permit_number)->not->toBeEmpty();
});

it('shows that certificate on the applicant’s own permit list straight away', function () {
    /*
     * Through the endpoint the Profile reads, not through the model. The
     * question is whether it REACHES the applicant — a permit row that exists
     * and is filtered out of `/permits` would satisfy the test above and still
     * leave the client's complaint intact.
     */
    $app = filingAwaitingItsPermits();
    clearanceApproved($app, 'CEC');

    $listed = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/permits?per_page=100')
        ->assertOk()
        ->json('data');

    $mine = collect($listed)->firstWhere('application.id', $app->id);

    expect($mine)->not->toBeNull(
        'the CEC was issued but does not reach the applicant’s permit list, so the Profile cannot show it'
    );
    expect($mine['permit_type']['code'])->toBe('CEC');
});

it('does not release the four the offices have not finished', function () {
    /*
     * The other half, and the one that would fail if "release early" were ever
     * implemented as "release everything early". Each certificate waits for ITS
     * OWN office and nobody else's.
     */
    $app = filingAwaitingItsPermits();
    clearanceApproved($app, 'CEC');

    $codes = $app->refresh()->load('permits.permitType')->permits
        ->pluck('permitType.code')
        ->all();

    foreach (['SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'BUSINESS'] as $code) {
        expect($codes)->not->toContain(
            $code,
            "{$code} was issued on the strength of another office's approval"
        );
    }
});

it('opens the DENR permits as Other Requirements when the CEC is issued', function () {
    /*
     * The client's chosen answer to "where do they comply these?" (option 1,
     * 9 September 2026): the system raises them, in the bin that already exists
     * for one office asking one applicant for one document.
     *
     * The business here is CENRO's catch-all row — a sari-sari store is not on
     * their table — which needs a CNC and nothing else. That is the ordinary
     * case rather than a special one, and it proves the list is derived rather
     * than hardcoded to the full six.
     */
    $app = filingAwaitingItsPermits();
    clearanceApproved($app, 'CEC');

    $cenro = Department::where('code', 'CENRO')->firstOrFail();
    $raised = OfficerRequest::where('application_id', $app->id)
        ->where('department_id', $cenro->id)
        ->get();

    expect($raised)->not->toBeEmpty('issuing the CEC raised no DENR requirement to comply with');
    expect($raised->pluck('title')->all())->toContain('DENR CNC — Certificate on Non-Coverage');

    $first = $raised->first();
    expect($first->requested_by_user_id)->toBeNull('a system-raised request must name no officer');
    expect($first->status)->toBe(OfficerRequestStatus::Pending);
    expect($first->due_date)->not->toBeNull();

    // Six months from issuance, which is the paper's rule and the whole point of
    // raising these at grant time rather than whenever somebody remembers.
    expect($first->due_date->toDateString())
        ->toBe(now()->addMonths(6)->toDateString());
});

it('does not raise the same DENR requirement twice', function () {
    /*
     * `grantClearance` is reachable more than once — a re-inspection conducted
     * after a permit was granted runs it again — so a second pass must find the
     * rows rather than duplicate them. A duplicated obligation is worse than a
     * missing one: it asks the applicant for the same certificate twice and
     * gives CENRO two rows to close.
     */
    $app = filingAwaitingItsPermits();
    $row = clearanceApproved($app, 'CEC');

    $before = OfficerRequest::where('application_id', $app->id)->count();
    expect($before)->toBeGreaterThan(0);

    app(WorkflowService::class)->transitionClearance($row, ClearanceStatus::Approved);
    $inspection = $app->inspections()->latest('id')->firstOrFail();
    app(WorkflowService::class)->recordInspection($inspection, InspectionResult::Passed, 'Re-checked.');

    expect(OfficerRequest::where('application_id', $app->id)->count())->toBe($before);
});
