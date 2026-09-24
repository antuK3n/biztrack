<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * The Business Permit is released at PAYMENT, and a refused clearance suspends it.
 *
 * ── The rule this file pins, and the one it replaces ─────────────────────────
 *
 * The LGU clarified the new-application flow on 24 September 2026: *"after
 * payment, business permit is already released, but can be suspended if the
 * other permits applied to were rejected."*
 *
 * Until then the certificate was minted by `approveOverall()` at the very end,
 * on the strength of all five clearances — so the applicant could not trade
 * until every office had finished, and an office that would not sign simply
 * held the filing open for ever. Withholding WAS the sanction.
 *
 * Inverting that changes what has to be true in three places, and this file
 * asserts each of them rather than the one that is easiest to reach:
 *
 *   1. the certificate exists, numbered and Active, the moment the money lands;
 *   2. a refusal an office can actually record — `ClearanceStatus::Rejected`,
 *      which was deleted on 17 September and is back because the sanction now
 *      needs a trigger;
 *   3. the certificate goes to Suspended when that happens, and comes back.
 *
 * The negative cases matter as much as the positive ones here. A renewal and an
 * amendment must NOT release at payment — both have a real BPLO act left — and
 * BPLO must not be able to refuse a permit through the office door, because the
 * row it would refuse is the one that issued the certificate.
 */

/** A new filing, paid, with the five clearances applied for and in front of their offices. */
function paidNewFiling(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Released At Payment '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Suspension Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);
    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])
        ->assertCreated();

    /*
     * Apply and hand in, for each of the five. Apply alone only opens the form;
     * submitting the sheet is what routes the office, which is what gives the
     * tests below an assignment to press Reject on.
     */
    foreach (['SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC'] as $code) {
        authAs('owner@biztrack.local');
        test()->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")->assertOk();
        test()->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
            'form_data' => [],
            'submit' => true,
        ])->assertSuccessful();
    }

    return Application::findOrFail($appId)->fresh();
}

/**
 * The Business Permit this filing released, freshly read.
 *
 * Not `outcomePermit` — RenewalOutcomesTest already declares one, and Pest
 * helpers share a global namespace across every file in the suite.
 */
function businessPermitOf(Application $app): ?Permit
{
    return Permit::where('application_id', $app->id)
        ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
        ->first();
}

/** The office account that speaks for the department issuing $code. */
const REFUSING_OFFICE = [
    'SANITARY' => 'sanitary@biztrack.local',
    'FSIC' => 'fire@biztrack.local',
    'ZONING' => 'zoning@biztrack.local',
];

/**
 * Move one permit to ForInspection, which is the only stage a refusal is
 * legal from.
 *
 * ── Why every refusal below needs this now ──────────────────────────────
 *
 * Client's correction, 24 September 2026: *"rejection comes mainly from 'For
 * Inspection', not 'For Approval'"*. A refusal suspends the business permit,
 * and that is only defensible once somebody has been to look — everything
 * wrong at the reading stage is a document or an answer, and both are
 * fixable without costing the applicant their permit.
 *
 * The office's Approve on the paperwork is what does it: it moves the permit
 * to ForInspection and books nothing, because the September flow has the
 * office choose the date separately.
 */
function readyToRefuse(Application $app, string $permitCode): int
{
    $assignmentId = officeAssignmentFor($app, $permitCode);

    test()->withHeaders(authAs(REFUSING_OFFICE[$permitCode]))
        ->postJson("/api/v1/assignments/{$assignmentId}/approve")->assertOk();

    return $assignmentId;
}

/** That office's open assignment on this filing. */
function officeAssignmentFor(Application $app, string $permitCode): int
{
    $departmentId = PermitType::where('code', $permitCode)->value('issuing_department_id');

    return $app->assignments()->where('department_id', $departmentId)->value('id');
}

/**
 * The applicant applies again after a refusal, and the office grants it.
 *
 * ── Why this is four calls and not one ───────────────────────────────────────
 *
 * All five clearances require a site visit, so an office's Approve moves the
 * permit to ForInspection and STOPS. Nothing is granted and no certificate is
 * minted until the visit passes — which is also when the suspension is
 * reconsidered, because `grantClearance` is the one place a clearance becomes
 * Approved.
 *
 * Both tests below got this wrong in their first draft, approving the paperwork
 * and expecting the business permit back. The rule they were accidentally
 * asserting is the better one anyway: a refusal is settled when the permit is
 * actually granted, not when an office agrees to look at it again.
 */
function reapplyAndGrant(Application $app, string $permitCode): void
{
    $office = REFUSING_OFFICE[$permitCode];

    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/clearances/{$permitCode}/apply")->assertOk();
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/{$permitCode}", [
        'form_data' => [],
        'submit' => true,
    ])->assertSuccessful();

    $assignmentId = officeAssignmentFor($app->fresh(), $permitCode);
    test()->withHeaders(authAs($office))
        ->postJson("/api/v1/assignments/{$assignmentId}/approve")->assertOk();

    $visitId = test()->withHeaders(authAs($office))
        ->postJson("/api/v1/applications/{$app->id}/permits/{$permitCode}/inspection", [
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertCreated()->json('data.id');

    test()->withHeaders(authAs($office))
        ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'])
        ->assertOk();
}

it('releases the business permit as soon as the money lands', function () {
    $app = paidNewFiling();

    /*
     * The certificate itself, not the pivot. A row reading Approved with no
     * `permits` row behind it would pass a status assertion and leave the
     * applicant with nothing to download, which is the failure that matters.
     */
    $permit = businessPermitOf($app);

    expect($permit)->not->toBeNull('paying did not mint the business permit')
        ->and($permit->permit_number)->not->toBeEmpty()
        ->and($permit->status)->toBe(PermitStatus::Active)
        ->and($permit->issued_at)->not->toBeNull();

    // And the filing is still open, because the other five are still running.
    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
});

it('does not close the filing just because the permit is out', function () {
    /*
     * The distinction the whole design rests on. `approved` is terminal —
     * `Inspection::canBeReinspected()` refuses a visit on a decided filing, and
     * the RA 11032 analytics measure submission→approved — so a filing that
     * became Approved at payment would stop the five offices inspecting and
     * would report days-to-payment as its processing time.
     */
    $app = paidNewFiling();

    expect($app->status->isTerminal())->toBeFalse()
        ->and($app->decided_at)->toBeNull();
});

it('leaves a renewal and an amendment to BPLO, releasing nothing at payment', function () {
    /*
     * Both go back to BPLO after paying, for reasons that outlive this change:
     * a renewal's certificates are copies BPLO must read, and an amendment is
     * completed at the BPLO window by the LGU's own paper. Issuing ahead of
     * either would be issuing over the decision rather than before it.
     *
     * Asserted on the BRANCH rather than by walking a renewal end to end. A
     * renewal needs a prior permit to renew — `prior_permit_id` is required, and
     * rightly — so building one here would mean issuing a certificate first,
     * which is a second full lifecycle for a fact one line wide. The renewal
     * flow itself is RenewalSkipsGatheringTest's subject; what belongs here is
     * that the release is keyed on the same test that routes the filing, so the
     * two can never disagree about which filings it applies to.
     */
    $source = file_get_contents(base_path('app/Services/WorkflowService.php'));

    expect($source)->toContain('if (! $backToBplo) {')
        ->and($source)->toContain('$this->releaseOutcomePermit($app);');

    /*
     * And that `$backToBplo` is the renewal/amendment test, not something that
     * has drifted to mean anything else. Reading the source is a blunt way to
     * assert a branch and is the honest one here: the alternative is a fixture
     * that costs more to maintain than the rule it guards.
     */
    expect($source)->toContain("[ApplicationType::Renewal, ApplicationType::Amendment],");
});
it('suspends the business permit when an office refuses one of the others', function () {
    $app = paidNewFiling();
    $permit = businessPermitOf($app);
    expect($permit->status)->toBe(PermitStatus::Active);

    $assignmentId = readyToRefuse($app, 'SANITARY');
    test()->withHeaders(authAs(REFUSING_OFFICE['SANITARY']))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", [
            'reason' => 'The premises have no potable water connection.',
            'remedy' => 'Connect to the mains supply, or file a deep-well permit, then apply again.',
        ])
        ->assertOk();

    // The permit's own row, and the certificate it issued.
    $row = $app->fresh()->permitTypes->firstWhere('code', 'SANITARY');

    expect($row->pivot->status)->toBe(ClearanceStatus::Rejected)
        ->and($permit->fresh()->status)->toBe(PermitStatus::Suspended)
        // Suspended is not live: everything that asks "may they trade" reads this.
        ->and($permit->fresh()->status->isLive())->toBeFalse();
});

it('refuses a rejection with no reason', function () {
    /*
     * The reason is the only thing that tells the applicant what would fix it,
     * and a suspension with none is a closed business and a dead end.
     */
    $app = paidNewFiling();

    $assignmentId = readyToRefuse($app, 'FSIC');
    test()->withHeaders(authAs(REFUSING_OFFICE['FSIC']))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", ['reason' => '', 'remedy' => 'x'])
        ->assertStatus(422);

    expect(businessPermitOf($app)->fresh()->status)->toBe(PermitStatus::Active);
});

it('restores the permit once nothing on the filing is refused', function () {
    $app = paidNewFiling();
    $permit = businessPermitOf($app);

    $assignmentId = readyToRefuse($app, 'ZONING');
    test()->withHeaders(authAs(REFUSING_OFFICE['ZONING']))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", [
            'reason' => 'The use is not allowed at this address.',
            'remedy' => 'Move to a commercial zone, or amend the line of business, then apply again.',
        ])
        ->assertOk();
    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);

    /*
     * The way back the client chose: the applicant applies again, hands the
     * sheet in, and the office grants it. `Rejected → ForApproval` is legal,
     * and the ordinary apply path is the only door — there is no separate
     * re-file mechanism to keep in step with this one.
     */
    reapplyAndGrant($app, 'ZONING');

    expect($app->fresh()->permitTypes->firstWhere('code', 'ZONING')->pivot->status)
        ->toBe(ClearanceStatus::Approved)
        ->and($permit->fresh()->status)->toBe(PermitStatus::Active);
});

it('keeps the permit suspended while a SECOND refusal still stands', function () {
    /*
     * Two offices can refuse one filing, and the rule is written as "is anything
     * still refused" rather than "was this the one that caused it" for exactly
     * this case. Reinstating on the first approval would hand the certificate
     * back while a live refusal stood.
     */
    $app = paidNewFiling();
    $permit = businessPermitOf($app);

    foreach (['SANITARY', 'FSIC'] as $code) {
        $id = readyToRefuse($app, $code);
        test()->withHeaders(authAs(REFUSING_OFFICE[$code]))
            ->postJson("/api/v1/assignments/{$id}/reject", [
                'reason' => "The {$code} requirements are not met.",
                'remedy' => "Meet the {$code} requirements and apply again.",
            ])
            ->assertOk();
    }
    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);

    /*
     * Settle one of the two, ALL THE WAY to granted. Stopping at the office's
     * Approve would leave the permit at ForInspection, and this test would
     * then pass because nothing had been granted rather than because the
     * second refusal was holding — the assertion would be true for a reason
     * that has nothing to do with its subject.
     */
    reapplyAndGrant($app, 'SANITARY');

    expect($app->fresh()->permitTypes->firstWhere('code', 'SANITARY')->pivot->status)
        ->toBe(ClearanceStatus::Approved)
        ->and($permit->fresh()->status)->toBe(
            PermitStatus::Suspended,
            'the permit came back while the FSIC refusal was still standing',
        );
});

it('lets BPLO lift a suspension, and records why', function () {
    $app = paidNewFiling();
    $permit = businessPermitOf($app);

    $assignmentId = readyToRefuse($app, 'SANITARY');
    test()->withHeaders(authAs(REFUSING_OFFICE['SANITARY']))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", [
            'reason' => 'Refused in error.',
            'remedy' => 'Nothing — this was filed against the wrong business.',
        ])
        ->assertOk();
    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/lift-suspension", [
            'reason' => 'CHO confirmed the refusal was filed against the wrong business.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    /*
     * The refusal STANDS. BPLO lifting a suspension is not BPLO granting
     * another office's permit — the sanitary row is still Rejected, and what
     * has been decided is that the business may trade while it is unsettled.
     */
    $row = $app->fresh()->permitTypes->firstWhere('code', 'SANITARY');
    expect($row->pivot->status)->toBe(ClearanceStatus::Rejected);
});

it('keeps the lift to BPLO', function () {
    // An office reviewer and the owner both have to be refused; the owner in
    // particular could otherwise undo their own suspension.
    $app = paidNewFiling();
    $permit = businessPermitOf($app);

    $fsicId = readyToRefuse($app, 'FSIC');
    test()->withHeaders(authAs(REFUSING_OFFICE['FSIC']))
        ->postJson("/api/v1/assignments/{$fsicId}/reject", [
            'reason' => 'No fire exits.',
            'remedy' => 'Install a second fire exit to code, then apply again.',
        ])
        ->assertOk();

    foreach (['sanitary@biztrack.local', 'owner@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))
            ->postJson("/api/v1/permits/{$permit->id}/lift-suspension", ['reason' => 'Trying it on.'])
            ->assertForbidden();
    }

    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);
});

it('does not let BPLO refuse a permit through the office door', function () {
    /*
     * The row BPLO holds is the BUSINESS one — the row that ISSUED the
     * certificate. Refusing it would suspend the permit for the absence of
     * itself. BPLO's refusal is `rejectApplication`, a different act.
     */
    $app = paidNewFiling();
    $bploAssignment = officeAssignmentFor($app, PermitType::OUTCOME_CODE);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bploAssignment}/reject", [
            'reason' => 'Not through here.',
            'remedy' => 'Not applicable.',
        ])
        ->assertStatus(422);

    expect(businessPermitOf($app)->fresh()->status)->toBe(PermitStatus::Active);
});

it('does not let an office un-issue a permit it has already granted', function () {
    /*
     * Once a certificate is minted and numbered, taking it back is a revocation
     * of a legal instrument. That is `PermitStatus::Revoked`, which has no
     * writer and should not gain one by accident through this control.
     */
    $app = paidNewFiling();
    $assignmentId = officeAssignmentFor($app, 'CEC');

    /*
     * All the way to ISSUED, which takes the visit as well as the reading.
     * Approving the paperwork moves the permit to ForInspection and stops —
     * that is the September flow — so a test that stopped there would be
     * refusing a permit that had not been granted, which IS allowed and is
     * not what this is about.
     */
    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignmentId}/approve")->assertOk();

    $visitId = test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/permits/CEC/inspection", [
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertCreated()->json('data.id');

    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'])
        ->assertOk();

    $row = $app->fresh()->permitTypes->firstWhere('code', 'CEC');
    expect($row->pivot->status)->toBe(ClearanceStatus::Approved);

    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", [
            'reason' => 'Changed my mind.',
            'remedy' => 'Not applicable.',
        ])
        ->assertStatus(422);
});

it('refuses a rejection with no remedy, and keeps the refusal after a re-application', function () {
    /*
     * ── Two rules in one walk, because they are the same loop ────────────────
     *
     * A refusal must say what would settle it — client's decision,
     * 24 September 2026. A reason is a verdict and a remedy is a route, and the
     * applicant is holding a suspended business permit until they can follow
     * one.
     *
     * And the refusal has to SURVIVE the re-application it causes.
     * `submitClearanceForm` clears `remarks` when the sheet is handed back in —
     * correctly, the instruction has been answered — so without the dedicated
     * columns the row returned to the office as a clean `for_approval` and the
     * officer re-reading it could approve, in good faith, exactly what they had
     * refused. That matters more than it sounds: the sheet reopens with every
     * answer still in it, so the form can come back identical.
     */
    $app = paidNewFiling();
    $assignmentId = readyToRefuse($app, 'SANITARY');

    test()->withHeaders(authAs(REFUSING_OFFICE['SANITARY']))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", ['reason' => 'No water.'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('remedy');

    test()->withHeaders(authAs(REFUSING_OFFICE['SANITARY']))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", [
            'reason' => 'The premises have no potable water connection.',
            'remedy' => 'Connect to the mains supply, then apply again.',
        ])
        ->assertOk();

    // The applicant sees both, on the card and on the sheet they reopen.
    $row = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$app->id}/clearances")
            ->assertOk()
            ->json('data')
    )->firstWhere('permit_type.code', 'SANITARY');

    expect($row['rejection_note'])->toContain('potable water')
        ->and($row['rejection_remedy'])->toContain('mains supply')
        ->and($row['rejected_at'])->not->toBeNull();

    /*
     * Re-apply and hand the SAME sheet back in — which is what an applicant can
     * do, because the answers are kept. The instruction clears; the refusal does
     * not.
     */
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
        'form_data' => [],
        'submit' => true,
    ])->assertSuccessful();

    $pivot = $app->fresh()->permitTypes->firstWhere('code', 'SANITARY')->pivot;

    expect($pivot->status)->toBe(ClearanceStatus::ForApproval)
        // Answered, so gone.
        ->and($pivot->remarks)->toBeNull()
        // Historical, so kept — this is what the officer's banner reads.
        ->and($pivot->rejected_at)->not->toBeNull()
        ->and($pivot->rejection_note)->toContain('potable water')
        ->and($pivot->rejection_remedy)->toContain('mains supply');
});

it('refuses a refusal before the inspection, and names the alternatives', function () {
    /*
     * ── The client's correction, pinned ──────────────────────────────────────
     *
     * *"Since rejection comes mainly from 'For Inspection', not 'For Approval'
     * part of the application... Can only Rejected during 'For Inspection'
     * cause the business permit to be suspended?"*
     *
     * Yes, and this is the guard. A refusal suspends a business's Mayor's
     * Permit, and that is only defensible once somebody has been to look —
     * everything wrong at the reading stage is a document or an answer, and
     * both are fixable without costing the applicant anything.
     *
     * The MESSAGE is asserted as well as the refusal. "A For Approval permit
     * cannot become Rejected" is what the legality table would have said, and
     * it tells an officer nothing about what to do instead.
     */
    $app = paidNewFiling();
    $permit = businessPermitOf($app);

    $refused = test()->withHeaders(authAs(REFUSING_OFFICE['SANITARY']))
        ->postJson('/api/v1/assignments/'.officeAssignmentFor($app, 'SANITARY').'/reject', [
            'reason' => 'I do not like the look of this.',
            'remedy' => 'Nothing.',
        ])
        ->assertStatus(422);

    $message = $refused->json('errors.status.0');

    expect($message)->toContain('after its inspection')
        // The two things they should do instead, both named.
        ->and($message)->toContain('return it for correction')
        ->and($message)->toContain('BPLO');

    // And nothing was written on the way to the refusal.
    expect($app->fresh()->permitTypes->firstWhere('code', 'SANITARY')->pivot->status)
        ->toBe(ClearanceStatus::ForApproval)
        ->and($permit->fresh()->status)->toBe(PermitStatus::Active);
});
