<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Validation\ValidationException;

/*
 * A renewal is approved by its offices, not re-read by BPLO.
 *
 * ── What this is for ────────────────────────────────────────────────────────
 *
 * For Final Approval left the NEW application path on 18 September 2026, when
 * the client asked *"what is the purpose of the BPLO checking if all other
 * permits are legit, when those permits are APPLIED DIRECTLY in BizTrack
 * itself?"* — and said renewals were not in scope.
 *
 * On 3 October 2026 they put renewals in scope: *"an admin verifying an
 * uploaded other permit will be useless if the system already tells them
 * whether they are still valid or not."*
 *
 * They are right, and the register agreed before the change was written: zero
 * renewals had ever reached the stage, and zero renewal clearances were in
 * upload mode. Reading those uploads was the stage's only stated job on a
 * renewal.
 *
 * Two guards, because either one alone leaves the other half standing — the
 * mode is refused at the door, and a ready renewal is approved rather than
 * parked. Each is asserted with its inverse, so neither can be passed by a
 * method that simply refuses or approves everything.
 */

/** A filing of one type with one required clearance part-way through. */
function ffaFiling(string $type, string $clearanceStatus = 'for_approval'): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Final Approval Test '.uniqid(),
        'registration_type' => 'DTI',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '1 Final Approval St',
        'status' => 'active',
    ]);

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => $type,
        'status' => 'approved',
    ]);

    $permitType = PermitType::whereIn('code', PermitType::REQUIRED_CLEARANCE_CODES)
        ->orderBy('id')
        ->firstOrFail();

    $app->permitTypes()->attach($permitType->id, ['status' => $clearanceStatus]);

    return [$app->fresh()->load('permitTypes'), $permitType];
}

it('refuses a handed-in copy on a renewal', function () {
    /*
     * The door. A renewal attaches only the permits the applicant TICKED, so a
     * certificate still in date is never on the filing at all — which means
     * what could be uploaded was a copy of one the applicant had just said
     * needs renewing, for BPLO to confirm what the register already knew.
     */
    [$app, $type] = ffaFiling('renewal');

    expect(fn () => app(WorkflowService::class)
        ->startClearance($app, $type, 'upload'))
        ->toThrow(ValidationException::class);
});

it('still refuses a handed-in copy on a new filing', function () {
    /*
     * The older half of the same guard (client, 29 September 2026: a new
     * business holds none of these yet), asserted so that widening the rule to
     * renewals cannot quietly drop the case it was written for.
     */
    [$app, $type] = ffaFiling('new');

    expect(fn () => app(WorkflowService::class)
        ->startClearance($app, $type, 'upload'))
        ->toThrow(ValidationException::class);
});

it('still accepts an ordinary APPLY on a renewal', function () {
    /*
     * The inverse of the door, and the reason it matters: refusing the upload
     * must not refuse the route that replaces it. Without this the first test
     * would pass just as well against a method that threw at everything.
     */
    [$app, $type] = ffaFiling('renewal', 'not_started');

    app(WorkflowService::class)->startClearance($app, $type, ApplicationPermitType::MODE_APPLY);

    expect($app->fresh()->load('permitTypes')->permitTypes->first()->pivot->mode)
        ->toBe(ApplicationPermitType::MODE_APPLY);
});

it('counts an unapproved clearance as outstanding whatever its mode', function () {
    /*
     * The deleted exception, from the other side. A renewal used to count an
     * uploaded copy as satisfied on the strength of its MODE, with no office
     * having approved anything. A row forced into that shape — which the door
     * now prevents, but older rows and direct writes do not — must still read
     * as outstanding.
     */
    [$app, $type] = ffaFiling('renewal');

    $app->permitTypes()->updateExistingPivot($type->id, [
        'mode' => 'upload',
        'status' => ClearanceStatus::ForApproval->value,
    ]);

    expect(app(WorkflowService::class)->outstandingClearances($app->fresh()->load('permitTypes')))
        ->toHaveCount(1);
});

it('approves a ready renewal instead of sending it to BPLO', function () {
    /*
     * The stage itself. Every required clearance approved leaves BPLO nothing
     * to weigh that the register does not already hold.
     *
     * Classified first, because the OTHER route to For Final Approval is
     * still live and would answer this question with the wrong yes — see the
     * test below, which is the first draft of this one, failing.
     */
    [$app, $type] = ffaFiling('renewal', 'approved');
    classifyAsOfficer($app);

    app(WorkflowService::class)->refreshReadiness($app->fresh()->load('permitTypes'));

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

it('still parks ANY filing that nobody has classified', function () {
    /*
     * The one route to For Final Approval that survives, and the reason the
     * status is not deleted outright: a filing nobody has given an RA 11032
     * tier must not have a permit issued against a statutory deadline the
     * tier decides. That has nothing to do with uploads and is not what the
     * client asked to remove.
     */
    [$app] = ffaFiling('renewal', 'approved');

    app(WorkflowService::class)->refreshReadiness($app);

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);
});

it('leaves a renewal where it is while a clearance is still outstanding', function () {
    /*
     * The inverse again, so the test above cannot be passed by a method that
     * moves every filing it is handed.
     */
    [$app] = ffaFiling('renewal');

    app(WorkflowService::class)->refreshReadiness($app);

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});
