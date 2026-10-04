<?php

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
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
        // The checklist is complete before the sheet goes in — the submit
        // refuses one that is not. See satisfyChecklist() in Pest.php.
        satisfyChecklist(
            Application::findOrFail($appId),
            PermitType::where('code', $code)->firstOrFail(),
        );
        test()->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
            'form_data' => [],
            'submit' => true,
        ])->assertSuccessful();
    }

    return Application::findOrFail($appId)->fresh();
}

/**
 * A filing stopped where BPLO is still reading it: submitted, nothing paid,
 * nothing issued.
 *
 * `paidNewFiling` above is driven all the way to a released certificate, which
 * is what most of this file is about. This is the other half of the rejection
 * pair — the state the feature was written for, where refusing the filing takes
 * nothing away from the applicant.
 */
function filingAtForApproval(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Nothing Released '.random_int(10000, 99999),
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
    // The checklist is complete before the sheet goes in — the submit
    // refuses one that is not. See satisfyChecklist() in Pest.php.
    satisfyChecklist(
        Application::findOrFail($app->id),
        PermitType::where('code', $permitCode)->firstOrFail(),
    );
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
    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

/*
 * ── Rejecting a filing whose permit is out ───────────────────────────────
 *
 * `rejectApplication` only ever wrote to the application row, which was
 * correct while the permit was minted at the very end. Since the release moved
 * to payment it was not: BPLO could reject a paid filing and leave the
 * certificate Active and still answering yes on the public /verify page.
 *
 * The first fix REFUSED the rejection, and the suite rejected the fix —
 * twelve tests reject paid filings, because ending a filing after payment is
 * an ordinary BPLO act and the "a decided filing takes no more site visits"
 * rules are defined against it. So the rejection stands and takes the
 * certificate with it.
 *
 * Asserted on the PERMIT as well as the status, because the status alone
 * passed the whole time the defect existed.
 */
it('suspends the released Business Permit when the filing is rejected', function () {
    $app = paidNewFiling();
    $permit = businessPermitOf($app);
    expect($permit->status)->toBe(PermitStatus::Active);

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Filed in error.'])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($permit->fresh()->status)->toBe(PermitStatus::Suspended);
});

/*
 * And a filing that released nothing is rejected without inventing a permit
 * to suspend — the ordinary case, and the one that would break loudly if the
 * lookup above stopped tolerating a null.
 */
it('rejects a filing that has released nothing, and touches no permit', function () {
    $app = filingAtForApproval();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Trade is prohibited at this address.'])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and(businessPermitOf($app->fresh()))->toBeNull();
});

it('does not close the filing just because the permit is out', function () {
    /*
     * The distinction the whole design rests on, and since 4 October 2026 it
     * is a distinction within one status rather than between two.
     *
     * A paid filing DOES reach `approved` now — the client had
     * `awaiting_other_permits` removed — so `status->isTerminal()` is true and
     * can no longer be asked. Everything that depended on it asks
     * `isDecided()`, which reads `decided_at`: `Inspection::canBeReinspected()`
     * refuses a visit on a decided filing, `ClearanceService::isUnlocked()`
     * shuts the clearance stage on one, and `rejectAssignment()` refuses an
     * office the right to turn a permit down. Were this to answer true at
     * payment, the five offices could neither inspect nor refuse, and the
     * applicant could not apply for a single other permit.
     *
     * The RA 11032 clock is unaffected either way: `FilingClock` measures
     * submission→`decided_at`, which is the same instant it always was.
     */
    $app = paidNewFiling();

    expect($app->isDecided())->toBeFalse()
        ->and($app->decided_at)->toBeNull()
        // The status alone says the opposite, which is the point.
        ->and($app->status->isTerminal())->toBeTrue();
});

it('names a paid filing still gathering Approved when BPLO is refused at it', function () {
    /*
     * Every screen calls this filing "Approved" (`Application::statusLabel()`);
     * the two refusals named the bare status, whose word is "Completed", so an
     * officer pressing Approve or a whole-form Return in a stale tab was told
     * the filing was finished (bplo-review 1).
     */
    $app = paidNewFiling();
    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->firstOrFail();

    authAs('bplo@biztrack.local');
    expect(test()->postJson("/api/v1/assignments/{$bplo->id}/approve")->assertStatus(422)->json('message'))
        ->toStartWith('There is nothing for BPLO to approve while this application is Approved.')
        ->and(test()->postJson("/api/v1/assignments/{$bplo->id}/return", ['remarks' => 'Stale tab.'])
            ->assertStatus(409)->json('message'))
        ->toBe('This application is Approved, so it cannot move to Returned. Refresh the filing to see its current state.');
});

it('leaves only an amendment to BPLO, releasing nothing at payment', function () {
    /*
     * An AMENDMENT alone, since 3 October 2026.
     *
     * It was a renewal and an amendment. The renewal's reason was that its
     * certificates are copies BPLO must read, and the client removed those:
     * *"an admin verifying an uploaded other permit will be useless if the
     * system already tells them whether they are still valid or not."* So a
     * renewal takes the new filing's path out of payment and its Mayor's
     * Permit is released there — which is the LGU's own rule of
     * 24 September, written about business permits and until now applied to
     * new filings alone.
     *
     * The amendment's reason outlives the change and is a different one: it
     * is completed at the BPLO window by the LGU's own paper, so issuing
     * ahead of that would be issuing over the decision rather than before
     * it.
     *
     * Asserted on the BRANCH rather than by walking a filing end to end,
     * for the reason the original gave: the release has to be keyed on the
     * same test that routes the filing, so the two can never disagree about
     * which filings it applies to. Reading the source is a blunt way to say
     * that and the honest one here.
     */
    $source = file_get_contents(base_path('app/Services/WorkflowService.php'));

    expect($source)->toContain('if ($backToBplo) {')
        ->and($source)->toContain('$this->releaseOutcomePermit($app);')
        ->and($source)->toContain('$backToBplo = $app->application_type === ApplicationType::Amendment;');

    /* And the renewal is no longer named by it. */
    expect($source)->not->toContain('[ApplicationType::Renewal, ApplicationType::Amendment],');
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

/*
 * The re-application has to reach somebody (office-review row 23).
 *
 * The refusal completes the office's assignment, and handing the sheet in
 * again only routed the office with `firstOrCreate` — which found the
 * completed row and left it completed. The permit sat For Approval with the
 * Business Permit suspended, on neither of the office's tabs, and the only
 * way anyone found it was by already knowing its id.
 */
it('puts a permit applied for again after a refusal back in its office’s queue', function () {
    $app = paidNewFiling();

    $assignmentId = readyToRefuse($app, 'SANITARY');
    test()->withHeaders(authAs(REFUSING_OFFICE['SANITARY']))
        ->postJson("/api/v1/assignments/{$assignmentId}/reject", [
            'reason' => 'No potable water connection.',
            'remedy' => 'Connect to the mains, then apply again.',
        ])
        ->assertOk();
    expect(ApplicationAssignment::find($assignmentId)->status)->toBe(AssignmentStatus::Completed);

    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
        'form_data' => [],
        'submit' => true,
    ])->assertSuccessful();

    $assignment = ApplicationAssignment::find($assignmentId);
    expect($app->fresh()->permitTypes->firstWhere('code', 'SANITARY')->pivot->status)
        ->toBe(ClearanceStatus::ForApproval)
        ->and($assignment->status)->toBe(AssignmentStatus::Pending)
        ->and($assignment->completed_at)->toBeNull();

    // The office's For Approval tab, as the queue screen asks for it.
    $queue = test()->withHeaders(authAs(REFUSING_OFFICE['SANITARY']))
        ->getJson('/api/v1/assignments?status=pending,in_progress,returned&per_page=200')
        ->assertOk()
        ->json('data');
    expect(collect($queue)->pluck('id'))->toContain($assignmentId);
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
    // The checklist is complete before the sheet goes in — the submit
    // refuses one that is not. See satisfyChecklist() in Pest.php.
    satisfyChecklist(
        Application::findOrFail($app->id),
        PermitType::where('code', 'SANITARY')->firstOrFail(),
    );
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
