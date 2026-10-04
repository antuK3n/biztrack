<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\SheetRequirements;
use Illuminate\Validation\ValidationException;

/*
 * An office sheet does not go in without the documents the office asks for.
 *
 * ── What this replaces ───────────────────────────────────────────────────────
 *
 * Until 30 September 2026 exactly one row on an office checklist was a gate:
 * the notarised Applicant Declaration, excepted on 17 September because the
 * paper prints MUST BE NOTARIZED PRIOR TO SUBMISSION OF APPLICATION in its own
 * capitals. Everything else — the lease, the tax declaration, the title — was
 * shown, said plainly, and submitted around, on the reading that CPDD's paper
 * is a counter checklist a clerk ticks on receipt rather than a gate.
 *
 * The client reversed it reading the same screen: *"Are the documentary fields
 * here not required? Make sure they are required."* The reason given for the
 * declaration's exception turns out to be the reason for all of them — an
 * office cannot act on a filing missing the documents its decision rests on,
 * so accepting one only buys the applicant a return trip and a second wait.
 *
 * ── Why the test is at this layer ────────────────────────────────────────────
 *
 * Because that is where the rule was NOT. The browser had the declaration gate
 * from 17 September and the server had nothing at all, so for a fortnight the
 * rule was a disabled button: anything that posted the submit itself went
 * through. Every case here calls the service directly, which is exactly the
 * route a disabled button does not cover.
 */

/**
 * A paid filing with the zoning sheet open and nothing attached to it.
 *
 * An owner rather than a lessee, so the checklist is the title branch of
 * MCG-CPDD-FO-003 — the branch whose rows are named below.
 */
function zoningSheetOpen(): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $business->update(['is_rented' => false, 'lessor_name' => null, 'lessor_address' => null]);

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

    $type = PermitType::where('code', 'ZONING')->firstOrFail();
    // Applying OPENS the sheet and stops there; submitting it is the act
    // under test.
    $workflow->startClearance($app->fresh(), $type, ApplicationPermitType::MODE_APPLY);

    return [$app->fresh(), $type];
}

it('refuses the sheet while the checklist is short of documents', function () {
    [$app, $type] = zoningSheetOpen();

    expect(fn () => app(WorkflowService::class)->submitClearanceForm($app, $type))
        ->toThrow(ValidationException::class);

    // And the permit did not move. A refusal that still routed the office
    // would be the worst of both: CPDD holding an incomplete sheet AND an
    // error on the applicant's screen saying it was not sent.
    $row = app(WorkflowService::class)->pivotFor($app->fresh(), 'ZONING');
    expect($row->status)->toBe(ClearanceStatus::NotStarted)
        ->and($row->submitted_at)->toBeNull();
});

it('names the documents it is waiting for, rather than counting them', function () {
    [$app, $type] = zoningSheetOpen();

    // "A document is missing" on a list of eleven sends the applicant back to
    // hunt for which one.
    try {
        app(WorkflowService::class)->submitClearanceForm($app, $type);
        $this->fail('The submit should have been refused.');
    } catch (ValidationException $e) {
        $message = $e->errors()['requirements'][0];
    }

    expect($message)->toContain('Applicant Declaration, notarised')
        ->and($message)->toContain('Tax Declaration');
});

it('lets the sheet in once every document is attached', function () {
    [$app, $type] = zoningSheetOpen();

    satisfyChecklist($app, $type);
    app(WorkflowService::class)->submitClearanceForm($app->fresh(), $type);

    $row = app(WorkflowService::class)->pivotFor($app->fresh(), 'ZONING');
    expect($row->status)->toBe(ClearanceStatus::ForApproval)
        ->and($row->submitted_at)->not->toBeNull();
});

it('marks every documentary row required, and the form itself not', function () {
    [$app] = zoningSheetOpen();

    $rows = collect(SheetRequirements::for($app->fresh(), 'ZONING'));

    /*
     * The `sheet` row is this form. Its `satisfied` is whether the sheet has
     * been submitted, which is false at the moment of submitting — so making
     * it block would mean the form could never be handed in, because what it
     * waits for is the act it is refusing. That is why the default is
     * `$source !== 'sheet'` and not a flat true.
     */
    $sheetRows = $rows->where('source', 'sheet');
    expect($sheetRows)->not->toBeEmpty()
        ->and($sheetRows->pluck('blocking')->unique()->all())->toBe([false]);

    $documentary = $rows->whereIn('source', ['upload', 'carried']);
    expect($documentary)->not->toBeEmpty()
        ->and($documentary->pluck('blocking')->unique()->all())->toBe([true]);
});

it('tells a carried row which attachment answers it', function () {
    [$app] = zoningSheetOpen();

    /*
     * A carried row is answered by a business-permit attachment and the
     * payload has to name WHICH — the note says it in prose, and prose is
     * not something a caller can resolve. It matters more now that such a
     * row can refuse a submit.
     *
     * And it has a slot of its own, so the refusal is never a dead end:
     * business permit documents cannot be added once the filing is paid,
     * and this stage begins after payment.
     */
    $carried = collect(SheetRequirements::for($app->fresh(), 'ZONING'))
        ->where('source', 'carried');

    expect($carried)->not->toBeEmpty();
    foreach ($carried as $row) {
        expect($row['carried_from'])->toBeString()
            ->and($row['code'])->not->toBeNull()
            ->and(App\Support\SheetRequirements::accepts('ZONING', $row['code']))->toBeTrue();
    }
});
