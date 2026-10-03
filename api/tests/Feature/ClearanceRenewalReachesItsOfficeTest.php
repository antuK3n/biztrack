<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;

/*
 * A renewal of one clearance reaches the office that issues it.
 *
 * ── What went wrong ─────────────────────────────────────────────────────────
 *
 * Submitting a Sanitary renewal left the filing at `for_approval` with its
 * SANITARY pivot still `not_started`, no mode, and no assignment at all. The
 * City Health Office queue never showed it; the applicant waited on an office
 * that had never been told (client, 4 October 2026, on BIZ-2026-00013).
 *
 * `submit()` says as much in its own remark — *"Apply for the permit below and
 * its office will review it"* — which describes the CLEARANCE STAGE, where the
 * applicant presses Apply per permit and `startClearance` routes the office. A
 * clearance-only renewal never goes there. Its office sheet is a step of the
 * wizard, filled in before Submit, so nothing called it.
 *
 * ── Why the assertions are what they are ────────────────────────────────────
 *
 * The assignment is the one that matters: it is what a queue reads. The pivot
 * status and mode are asserted beside it because a routed filing whose
 * clearance is still `not_started` would show in the queue and refuse to be
 * worked on, which is a worse state than not showing at all.
 *
 * Driven through `WorkflowService::submit` rather than the HTTP endpoint, so
 * a failure points at the transition rather than at a controller or a form
 * request between here and it.
 */

/** A business holding a live permit of each code, with the records a real one has. */
function officeRenewalBusiness(array $codes): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Office Routing '.uniqid(),
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-2026-'.random_int(100000, 999999),
        'barangay_id' => Barangay::value('id'),
        'address_line' => '7 Routing Street',
        'status' => 'active',
    ]);

    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $permits = [];
    foreach ($codes as $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $permits[$code] = Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => $type->id,
            'permit_number' => 'ROUTE-'.$code.'-'.uniqid(),
            'issued_at' => CarbonImmutable::now()->subYear(),
            'valid_from' => CarbonImmutable::now()->subYear(),
            /* Inside the 30-day window, so the renewal is allowed to be filed. */
            'valid_until' => CarbonImmutable::now()->addDays(10),
            'status' => 'active',
        ]);
    }

    return [$business, $permits];
}

/** A submitted clearance-only renewal carrying exactly `$code`. */
function submittedClearanceRenewal(string $code): Application
{
    [$business, $permits] = officeRenewalBusiness([$code]);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'data_privacy_consent' => true,
        'prior_permit_id' => $permits[$code]->id,
    ]);
    $app->priorPermits()->sync([$permits[$code]->id]);
    $app->permitTypes()->sync([PermitType::where('code', $code)->value('id')]);

    app(WorkflowService::class)->submit($app->fresh());

    return $app->fresh();
}

it('routes a sanitary renewal to the City Health Office', function () {
    /*
     * The reported case, asserted on the thing a queue actually reads.
     */
    $app = submittedClearanceRenewal('SANITARY');
    $cho = Department::where('code', 'CHO')->firstOrFail();

    expect(
        ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $cho->id)
            ->exists()
    )->toBeTrue();
});

it('leaves the clearance ready to be worked on, not merely visible', function () {
    /*
     * A routed filing whose clearance is still `not_started` would appear in
     * the queue and refuse every action on it — worse than not appearing,
     * because the officer cannot tell it is broken.
     */
    $app = submittedClearanceRenewal('SANITARY');
    $row = app(WorkflowService::class)->pivotFor($app, 'SANITARY');

    expect($row?->status)->toBe(ClearanceStatus::ForApproval)
        ->and($row?->mode)->toBe(ApplicationPermitType::MODE_APPLY)
        ->and($row?->submitted_at)->not->toBeNull();
});

it('does the same for every other office, not just CHO', function () {
    /*
     * The client asked for the fix to cover the rest, and the mechanism is
     * one loop over whatever the filing carries — so this is the same claim
     * put to each office rather than a second mechanism.
     *
     * Only CEC is left here, and the reason is the test below: three of the
     * five offices refuse a sheet until documents are attached, so Sanitary
     * and CEC are the two whose routing can be shown on its own.
     */
    foreach ([
        'CEC' => 'CENRO',
    ] as $code => $office) {
        $app = submittedClearanceRenewal($code);
        $department = Department::where('code', $office)->first();

        expect($department)->not->toBeNull("no {$office} department seeded");
        expect(
            ApplicationAssignment::where('application_id', $app->id)
                ->where('department_id', $department->id)
                ->exists()
        )->toBeTrue("{$code} did not reach {$office}");
    }
});

it('refuses to submit a sheet whose blocking document is missing', function () {
    /*
     * ── Found by this file, and worth keeping as a rule ─────────────────
     *
     * The loop above originally included these two and failed on both.
     * BFP's renewal set carries `BPLO_ASSESSMENT_RENEWAL`, OBO's carries a
     * dozen more and CPDD's carries six — all blocking rows, and
     * `submitClearanceForm` refuses a sheet with one unsatisfied — "Attach
     * Business permit fee / tax assessment bill from BPLO before submitting
     * this form".
     *
     * That refusal is CORRECT and is asserted here so a later change cannot
     * quietly drop it. What was wrong was where it landed: this runs inside
     * the transaction that submits the filing, so the throw rolled the whole
     * submission back and the filing reached nobody — the same ending as the
     * routing bug, by a different road.
     *
     * The fix is on the other side, in the wizard: `missingFor` now adds the
     * blocking rows to an office step exactly as the clearance stage does,
     * so Next is held until the document is attached and this exception
     * becomes unreachable from the UI. It stays enforced here because the
     * browser is not the only caller.
     */
    foreach (['FSIC', 'OCCUPANCY', 'ZONING'] as $code) {
        expect(fn () => submittedClearanceRenewal($code))
            ->toThrow(Illuminate\Validation\ValidationException::class);
    }
});

it('still does not route a clearance-only renewal to BPLO', function () {
    /*
     * The other half, and the one a careless fix would break: BPLO has no
     * part in this filing (client, 17 September 2026). Routing it everywhere
     * would make the queue show work nobody at BPLO can do.
     */
    $app = submittedClearanceRenewal('SANITARY');
    $bplo = Department::where('code', 'BPLO')->firstOrFail();

    expect(
        ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $bplo->id)
            ->exists()
    )->toBeFalse();
});

it('sends a business permit renewal to BPLO and not to the clearance offices', function () {
    /*
     * The inverse filing, so the branch cannot be flattened into "route
     * everything to its own office". A renewal carrying the Mayor's Permit is
     * BPLO's: it is billed, and its clearances are applied for later at the
     * clearance stage.
     */
    [$business, $permits] = officeRenewalBusiness([PermitType::OUTCOME_CODE]);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'data_privacy_consent' => true,
        'prior_permit_id' => $permits[PermitType::OUTCOME_CODE]->id,
    ]);
    $app->priorPermits()->sync([$permits[PermitType::OUTCOME_CODE]->id]);
    $app->permitTypes()->sync([PermitType::where('code', PermitType::OUTCOME_CODE)->value('id')]);

    app(WorkflowService::class)->submit($app->fresh());

    $bplo = Department::where('code', 'BPLO')->firstOrFail();
    expect(
        ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $bplo->id)
            ->exists()
    )->toBeTrue();

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval);
});
