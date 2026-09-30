<?php

use App\Enums\PaymentStatus;
use App\Models\ApplicationAssignment;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;

/*
 * Where a blank lands in a sorted list, stated rather than left to the engine.
 *
 * SQLite treats NULL as the lowest value: first ascending, last descending.
 * PostgreSQL does the reverse unless ORDER BY says NULLS FIRST / NULLS LAST.
 * Every list below was built and tested on SQLite, so SQLite's placement is the
 * one testers have seen; these pin it on both engines. Run under
 * phpunit.pgsql.xml, each of them fails against the unstated ORDER BY.
 */

it('puts an old-register permit, which has no tracking ID, first ascending and last descending', function () {
    $business = Permit::whereHas('business')->firstOrFail()->business;
    $legacy = Permit::create([
        'business_id' => $business->id,
        'permit_type_id' => PermitType::where('code', 'BUSINESS')->value('id'),
        'permit_number' => 'OLD-SORT-1',
        'valid_from' => now()->subYear()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now()->subYear(),
        'status' => 'active',
    ]);
    expect($legacy->application_id)->toBeNull();

    $page = fn (string $dir, int $page = 1) => test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/permits?detail=1&per_page=50&sort=tracking_id&dir={$dir}&page={$page}")
        ->assertOk();

    expect($page('asc')->json('data.0.application'))->toBeNull();

    $first = $page('desc');
    expect($first->json('data.0.application.tracking_id'))->not->toBeNull();
    $last = $page('desc', (int) $first->json('meta.last_page'))->json('data');
    expect(end($last)['application'])->toBeNull();
});

it('lists a payment not yet paid after the paid ones in the owner’s history', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $paid = Payment::whereHas('application', fn ($a) => $a->where('applicant_user_id', $owner->id))
        ->whereNotNull('paid_at')->firstOrFail();
    $pending = Payment::create([
        'application_id' => $paid->application_id,
        'fee_assessment_id' => $paid->fee_assessment_id,
        'reference_number' => 'PAY-SORT-PENDING',
        'amount' => 100,
        'method' => 'gcash',
        'status' => PaymentStatus::Pending,
    ]);

    $ids = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/payments?per_page=100')->assertOk()->json('data'))->pluck('id');

    expect($ids->first())->not->toBe($pending->id)
        ->and($ids->last())->toBe($pending->id);
});

it('lists an unscheduled inspection after the dated ones', function () {
    // The sanitary office's own list: inspections are read office by office.
    $on = ApplicationAssignment::whereHas('department', fn ($d) => $d->where('code', 'CHO'))
        ->whereHas('application', fn ($a) => $a->whereNull('deleted_at'))->firstOrFail();
    $visit = fn (?string $at) => Inspection::create([
        'application_id' => $on->application_id,
        'department_id' => $on->department_id,
        'status' => 'scheduled',
        'scheduled_at' => $at,
    ]);
    $visit(now()->addDay()->toDateTimeString());
    $unscheduled = $visit(null);
    $visit(now()->subDay()->toDateTimeString());

    $response = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/inspections?per_page=100')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($response->json('meta.last_page') ?? 1)->toBe(1)
        ->and($ids->first())->not->toBe($unscheduled->id)
        ->and($ids->last())->toBe($unscheduled->id);
});

it('leads the unheld reviews with one that was released and has no assigned date', function () {
    $unheld = ApplicationAssignment::whereNull('officer_user_id')->whereNotNull('assigned_at')
        ->whereHas('application', fn ($a) => $a->whereNull('deleted_at'))->firstOrFail();
    $released = ApplicationAssignment::whereKeyNot($unheld->id)
        ->whereHas('application', fn ($a) => $a->whereNull('deleted_at'))->firstOrFail();
    $released->forceFill(['officer_user_id' => null, 'assigned_at' => null])->save();

    $rows = collect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/oic-assignments?per_page=200')->assertOk()->json('data'));

    expect($rows->first()['id'])->toBe($released->id);
});
