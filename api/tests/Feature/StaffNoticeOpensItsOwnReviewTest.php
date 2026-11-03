<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\OfficerRequest;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\NotificationService;

/*
 * A notice to an officer opens that officer's own review of the filing.
 *
 * `/staff/queue/{id}` binds an ASSIGNMENT. Officer notices carried the
 * APPLICATION id into it, and ReviewPage re-resolves a number only after a
 * 404 — so where the filing's number was another filing's review, the
 * officer opened that other filing. Ken, 21 October 2026: link to the
 * office's own case, as the "New application" notice does. An owner's
 * notices keep the citizen address.
 *
 * Helpers are prefixed `own`: Pest helpers share one namespace.
 */

function ownAccount(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

function ownBplo(): int
{
    return Department::where('code', 'BPLO')->value('id');
}

/** A new filing of owner@, saved with its documents and not yet submitted. */
function ownDraft(string $name): int
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '8 Review Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);

    return $appId;
}

/**
 * A submitted filing whose number is NOT its BPLO review's number.
 *
 * Two drafts, submitted in the reverse order, so the reviews are numbered
 * against the grain: on a register where the two sequences run level, each
 * filing's id is the number of the other one's review. The expectation below
 * is the fixture refusing to pass vacuously if that ever stops being true.
 *
 * @return array{0: Application, 1: ApplicationAssignment}
 */
function ownCrossedFiling(): array
{
    $first = ownDraft('Crossed A');
    $second = ownDraft('Crossed B');

    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$second}/submit")->assertOk();
    test()->postJson("/api/v1/applications/{$first}/submit")->assertOk();

    $app = Application::findOrFail($second);
    $review = ApplicationAssignment::where('application_id', $second)
        ->where('department_id', ownBplo())
        ->firstOrFail();

    expect($review->id)->not->toBe($app->id);

    return [$app, $review];
}

function ownLatestLink(User $reader, string $title): ?string
{
    return AppNotification::where('user_id', $reader->id)
        ->where('title', $title)
        ->latest('id')
        ->value('link');
}

it('opens the officer’s own review from a message, not the review sharing the filing’s number', function () {
    [$app, $review] = ownCrossedFiling();
    // An owner may write to an office once an officer there holds the case.
    takeFiling($app->id, 'BPLO');

    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/messages", [
        'body' => 'Is anything still missing?',
        'department_id' => ownBplo(),
    ])->assertCreated();

    expect(ownLatestLink(ownAccount('bplo@biztrack.local'), 'New message'))->toBe("/staff/queue/{$review->id}")
        // The number the link used to carry is a different filing's review.
        ->and(ApplicationAssignment::find($app->id)?->application_id)->not->toBe($app->id);
});

it('opens the officer’s own review when the owner answers their requirement', function () {
    [$app, $review] = ownCrossedFiling();
    $officer = ownAccount('bplo@biztrack.local');
    $requirement = OfficerRequest::create([
        'application_id' => $app->id,
        'requested_by_user_id' => $officer->id,
        'department_id' => $officer->department_id,
        'title' => 'Barangay clearance',
        'request_type' => 'document',
        'status' => 'pending',
    ]);

    app(NotificationService::class)->requestResponded($requirement->load('application'), $officer);

    expect(ownLatestLink($officer, 'Requirement response received'))->toBe("/staff/queue/{$review->id}");
});

it('opens BPLO’s own review from Corrections received', function () {
    [$app, $review] = ownCrossedFiling();

    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/assignments/{$review->id}/return", ['remarks' => 'Send the lease contract.'])
        ->assertOk();
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/resubmit")->assertOk();

    expect(ownLatestLink(ownAccount('bplo@biztrack.local'), 'Corrections received'))->toBe("/staff/queue/{$review->id}");
});

it('keeps an owner’s notices on the citizen site', function () {
    [$app] = ownCrossedFiling();
    takeFiling($app->id, 'BPLO');

    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/messages", [
        'body' => 'Please send the lease contract.',
        'department_id' => ownBplo(),
    ])->assertCreated();

    expect(ownLatestLink(ownAccount('owner@biztrack.local'), 'New message'))->toBe("/applications/{$app->id}");
});

it('sends an officer whose office has no review of the filing to the queue, never to another filing', function () {
    [$app] = ownCrossedFiling();
    // Routed to BPLO alone: the City Health Office has no case on it.
    $officer = ownAccount('sanitary@biztrack.local');

    app(NotificationService::class)->newMessage($app, $officer);

    expect(ownLatestLink($officer, 'New message'))->toBe('/staff/queue');
});
