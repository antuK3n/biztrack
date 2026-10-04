<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\OfficerRequest;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;

/*
 * ── A TIN left blank is asked for, automatically ─────────────────────────────
 *
 * Client, 27 September 2026, having filed with item 3 empty: *"why it did not
 * appear on the Other Requirements? When is it asked for me to fill up my TIN?
 * Do I have to wait for approval or what?"*
 *
 * The answer was "never". The TIN was optional on Section A, the officer's
 * review sheet printed the blank as `—` like every other skipped field, and
 * nothing in the system flagged it, blocked on it or asked for it. The Confirm
 * step's promise that an officer would chase it rested entirely on an officer
 * noticing one em dash among forty.
 *
 * These tests pin the three facts that make the promise real: it is raised
 * without being asked for, it does not hold up approval, and answering it puts
 * the number where the register can see it.
 */

/*
 * `filingWithoutTin` lives in tests/Pest.php — ReturnNotePerFieldTest needs
 * it too, and a helper declared in a test file does not exist until that
 * file is loaded, so a second file using it breaks the moment anyone runs
 * that second file on its own.
 */


/** The automatic TIN requirement on a filing, if one was raised. */
function tinRequirementOn(Application $app): ?OfficerRequest
{
    return OfficerRequest::where('application_id', $app->id)
        ->where('system_key', WorkflowService::TIN_REQUIREMENT_KEY)
        ->first();
}

it('raises a TIN requirement of its own when BPLO approves a filing with none', function () {
    $app = filingWithoutTin();
    expect(tinRequirementOn($app))->toBeNull();

    bploApprovesForm($app);

    $req = tinRequirementOn($app->fresh());

    /*
     * A MESSAGE, not a document. The client's own argument settles the kind:
     * supplying a TIN asks for no document, so withholding one cannot turn it
     * into a document requirement.
     */
    expect($req)->not->toBeNull()
        ->and($req->request_type)->toBe('message')
        ->and($req->status->value)->toBe('pending')
        // Nobody wrote it, so nobody is named as having written it.
        ->and($req->requested_by_user_id)->toBeNull();

    // And approval was not held up: a permit may lawfully issue without a TIN,
    // because the Mayor's Permit is an INPUT to BIR registration.
    expect($app->fresh()->status->value)->toBe('pending_payment');
});

it('raises nothing when the applicant gave a TIN', function () {
    $app = filingWithoutTin('123-456-789-000');

    bploApprovesForm($app);

    expect(tinRequirementOn($app->fresh()))->toBeNull();
});

it('does not stack a second copy if the approval ever runs twice', function () {
    $app = filingWithoutTin();
    bploApprovesForm($app);
    $first = tinRequirementOn($app->fresh());

    /*
     * The status is put back BY HAND, and that is the honest shape of this
     * test: no route reaches `approveMainForm` twice today, because the first
     * call leaves the filing at Pending Payment and the guard at the top
     * refuses anything that is not For Approval.
     *
     * The `exists` check in `raiseTinRequirement` is therefore defensive, and
     * it is pinned here rather than left to a reader's judgement — a retried
     * request, or a future flow that returns a billed filing to For Approval,
     * would otherwise hand the applicant the same question twice with no test
     * to notice.
     */
    $app->fresh()->update(['status' => 'for_approval']);
    app(WorkflowService::class)->approveMainForm(Application::findOrFail($app->id));

    expect(OfficerRequest::where('application_id', $app->id)
        ->where('system_key', WorkflowService::TIN_REQUIREMENT_KEY)
        ->count())->toBe(1)
        ->and(tinRequirementOn($app->fresh())->id)->toBe($first->id);
});

it('writes the answer onto the business when BPLO accepts it', function () {
    $app = filingWithoutTin();
    bploApprovesForm($app);
    $req = tinRequirementOn($app->fresh());

    // The applicant types the number — there is nothing to attach.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => '123-456-789-000'])
        ->assertOk();

    // Still not on the business: an unreviewed reply is not an answer.
    expect($app->fresh()->business->tin)->toBeNull();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/close", ['outcome' => 'fulfilled'])
        ->assertOk();

    expect($req->fresh()->status->value)->toBe('fulfilled')
        ->and($app->fresh()->business->tin)->toBe('123-456-789-000');
});

it('shapes a typed TIN the same way the application form does', function () {
    $app = filingWithoutTin();
    bploApprovesForm($app);
    $req = tinRequirementOn($app->fresh());

    // Typed without dashes, which is how most people read it off a certificate.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => '123456789000'])
        ->assertOk();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/close", ['outcome' => 'fulfilled'])
        ->assertOk();

    /*
     * One format, whichever door it came through. A TIN normalised one way by
     * the form and another way by a requirement reply would be two columns
     * wearing one name — which is why the shaping moved to App\Support\Tin
     * rather than being copied.
     */
    expect($app->fresh()->business->tin)->toBe('123-456-789-000');
});

it('refuses to accept a reply that is not a TIN, and records nothing', function () {
    $app = filingWithoutTin();
    bploApprovesForm($app);
    $req = tinRequirementOn($app->fresh());

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => 'I will bring it next week'])
        ->assertOk();

    /*
     * The acceptance itself fails. Closing the requirement and silently
     * skipping the write would mark the question answered while leaving the
     * column blank — the exact hole this whole change exists to remove.
     */
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/close", ['outcome' => 'fulfilled'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['outcome']);

    expect($app->fresh()->business->tin)->toBeNull()
        ->and($req->fresh()->status->value)->not->toBe('fulfilled');
});

it('still lets BPLO send a bad reply back', function () {
    $app = filingWithoutTin();
    bploApprovesForm($app);
    $req = tinRequirementOn($app->fresh());

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => 'I will bring it next week'])
        ->assertOk();

    // The guard above must not trap the requirement: refusing is always open.
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/close", [
            'outcome' => 'needs_resubmission',
            'remarks' => 'Please type the number itself, like 123-456-789-000.',
        ])
        ->assertOk();

    expect($req->fresh()->status->value)->toBe('needs_resubmission')
        ->and($app->fresh()->business->tin)->toBeNull();
});
