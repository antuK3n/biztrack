<?php

use App\Enums\AssignmentStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\OfficePerformanceAnalytics;
use Illuminate\Support\Facades\DB;

/*
 * A filing that has ended takes every office's open review with it.
 *
 * Rejecting a filing left the other offices' reviews `pending` for good, and
 * cancelling one left BPLO's. They sat in the For Approval queues and in every
 * open-backlog count. The workflow now closes them as the filing ends, and a
 * migration closes the ones already on the register.
 */

/** A submitted filing at For Approval, holding BPLO's open review. */
function deadFilingSubmitted(): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Dead Filing '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Closing Street', 'barangay_id' => Barangay::first()->id],
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

    return $appId;
}

it('closes the other offices’ open reviews when BPLO rejects the filing', function () {
    $appId = scopedAssignmentFiling('Rejected Later'); // BPLO done, CHO open
    $cho = ApplicationAssignment::findOrFail(choAssignmentId($appId));
    expect($cho->status)->toBe(AssignmentStatus::Pending);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/reject", ['reason' => 'The premises is not a lawful use.'])
        ->assertOk();

    $cho->refresh();
    expect($cho->status)->toBe(AssignmentStatus::Closed)
        // Nobody finished it, so no turnaround is measured from it.
        ->and($cho->completed_at)->toBeNull()
        // BPLO's own review was completed before the rejection and stays so.
        ->and(ApplicationAssignment::where('application_id', $appId)
            ->where('status', AssignmentStatus::Completed->value)->count())->toBe(1);
});

it('closes BPLO’s open review when the applicant cancels', function () {
    $appId = deadFilingSubmitted();
    $bplo = ApplicationAssignment::where('application_id', $appId)->sole();
    expect($bplo->status)->toBe(AssignmentStatus::Pending);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/cancel")
        ->assertOk();

    expect($bplo->fresh()->status)->toBe(AssignmentStatus::Closed);
});

it('drops a dead filing’s reviews from the offices’ open backlog', function () {
    $openBplo = fn () => collect(OfficePerformanceAnalytics::build()['offices'])->firstWhere('code', 'BPLO')['open'];

    $before = $openBplo();
    $appId = deadFilingSubmitted();
    expect($openBplo())->toBe($before + 1);

    test()->withHeaders(authAs('owner@biztrack.local'))->postJson("/api/v1/applications/{$appId}/cancel")->assertOk();

    expect($openBplo())->toBe($before);
});

it('closes, once and only on dead filings, the reviews the register already left open', function () {
    $business = anaBusiness();
    $dead = anaFiling($business, ['status' => 'rejected', 'submitted_at' => now()->subDays(9), 'decided_at' => now()->subDays(2)]);
    $cancelled = anaFiling($business, ['status' => 'cancelled', 'submitted_at' => now()->subDays(9)]);
    $live = anaFiling($business, ['status' => 'approved', 'submitted_at' => now()->subDays(9)]);

    $deadOpen = anaAssignment($dead, 'CHO', ['assigned_at' => now()->subDays(8)]);
    $deadReturned = anaAssignment($dead, 'BFP', ['assigned_at' => now()->subDays(8), 'status' => 'returned']);
    $deadDone = anaAssignment($dead, 'BPLO', ['assigned_at' => now()->subDays(9), 'completed_at' => now()->subDays(8)]);
    $cancelledOpen = anaAssignment($cancelled, 'BPLO', ['assigned_at' => now()->subDays(9)]);
    $liveOpen = anaAssignment($live, 'CHO', ['assigned_at' => now()->subDays(8)]);

    $migration = require database_path('migrations/2026_10_01_000200_close_the_reviews_a_dead_filing_left_open.php');
    $statuses = fn () => DB::table('application_assignments')
        ->whereIn('id', [$deadOpen, $deadReturned, $deadDone, $cancelledOpen, $liveOpen])
        ->orderBy('id')
        ->pluck('status', 'id')
        ->all();

    $migration->up();
    $once = $statuses();
    $migration->up();

    expect($once)->toBe([
        $deadOpen => 'closed',
        $deadReturned => 'closed',
        $deadDone => 'completed',
        $cancelledOpen => 'closed',
        $liveOpen => 'pending',
    ])
        ->and($statuses())->toBe($once)
        ->and(DB::table('application_assignments')->where('id', $deadOpen)->value('completed_at'))->toBeNull();
});

it('keeps a live filing’s reviews open when it moves on', function () {
    $appId = deadFilingSubmitted();

    bploApprovesForm(Application::findOrFail($appId));

    // Completed by BPLO's approval, not closed: closing is for filings that end.
    expect(ApplicationAssignment::where('application_id', $appId)->sole()->status)->toBe(AssignmentStatus::Completed);
});

it('keeps an open review of an ended filing out of the backlog even if it was never closed', function () {
    $openBplo = fn () => collect(OfficePerformanceAnalytics::build()['offices'])->firstWhere('code', 'BPLO')['open'];
    $before = $openBplo();

    // As the register held them before the workflow closed such rows.
    $business = anaBusiness();
    anaAssignment(anaFiling($business, ['status' => 'rejected', 'submitted_at' => now()->subDays(5)]), 'BPLO');
    anaAssignment(anaFiling($business, ['status' => 'cancelled', 'submitted_at' => now()->subDays(5)]), 'BPLO');

    expect($openBplo())->toBe($before);
});
