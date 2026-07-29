<?php

use App\Models\Business;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Support\Str;

/*
 * These identifiers used to be a row count plus one. Soft-deleting anything
 * made the count drop while the row (and its number) stayed in the table, so
 * the next insert reused the value and died on the unique index. The applicant
 * saw a 500 while doing nothing wrong.
 *
 * A hard delete is a different case: the row is gone, the number is genuinely
 * free, and reusing it cannot collide. These tests hold the line that matters
 * rather than demanding numbers never repeat under any circumstance.
 */

function probeBusiness(): Business
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    return Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Numbering Probe '.Str::random(6),
        'ban' => Numbering::ban(),
        'is_active' => true,
    ]);
}

function banSuffix(string $ban): int
{
    return (int) substr($ban, strrpos($ban, '-') + 1);
}

it('does not reuse a number held by a soft-deleted business', function () {
    $first = probeBusiness();
    $second = probeBusiness();
    expect($second->ban)->not->toBe($first->ban);

    $second->delete();
    $third = probeBusiness();

    // The soft-deleted row still holds its ban, so reusing it would collide.
    expect($third->ban)->not->toBe($second->ban)
        ->and($third->ban)->not->toBe($first->ban);
});

it('increments by one for each new business', function () {
    $a = probeBusiness();
    $b = probeBusiness();

    expect(banSuffix($b->ban))->toBe(banSuffix($a->ban) + 1);
});

it('creates a business through the API after a soft deletion', function () {
    // The end-to-end shape of the original failure: this returned 500.
    Business::withTrashed()->latest('id')->first()?->delete();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', [
            'name' => 'Post Deletion Store',
            'registration_type' => 'sole_proprietorship',
            'registration_number' => 'DTI-2026-7788',
            'tin' => '123-456-789-000',
            'address' => ['line1' => '5 Probe St', 'barangay_id' => 1],
            'lines' => [['psic_code_id' => 1]],
        ])
        ->assertCreated();
});

it('keeps tracking ids unique across a soft-deleted application', function () {
    // Same defect, same fix, different table: a cancelled application keeps its
    // tracking id, and anyone holding a printout still expects it to mean that
    // filing and not somebody else's.
    $first = Numbering::trackingId();
    $app = App\Models\Application::create([
        'business_id' => Business::withTrashed()->value('id'),
        'applicant_user_id' => User::where('email', 'owner@biztrack.local')->value('id'),
        'application_type' => 'new',
        'status' => 'draft',
        'tracking_id' => $first,
    ]);

    $app->delete();

    expect(Numbering::trackingId())->not->toBe($first);
});
