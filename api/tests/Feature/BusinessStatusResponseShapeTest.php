<?php

use App\Models\Business;

/*
 * What POST /admin/businesses/{id}/status answers with, and what it records.
 *
 * Two bugs on the Owner Status screen came out of this endpoint's response, and
 * both are worth pinning because both were invisible until an admin actually
 * changed a status:
 *
 * 1. The response is a *partial* business — id, status, status_label and
 *    nothing else. The screen folded it into the table by replacing the whole
 *    row, so the Business column went blank and Owner became "—" the moment a
 *    status changed; typing in the search box then threw on
 *    `r.name.toLowerCase()` and emptied the page. The screen now merges. This
 *    test says which fields a caller may and may not rely on, so that "merge,
 *    don't replace" has a stated reason rather than looking like caution.
 *
 * 2. The audit row records { from, to, reason }. The Status History modal read
 *    `changes.status` — a key that is never written — and fell back to 'active',
 *    so a blacklisting rendered in the timeline as "Active", in green, with the
 *    reason dropped. The keys are the contract; here they are.
 */

/** A business to move around, and the status it started on. */
function aBusinessToRestatus(): Business
{
    return Business::whereNotNull('owner_user_id')->firstOrFail();
}

it('answers a status change with only the status fields', function () {
    $business = aBusinessToRestatus();
    $admin = authAs('admin@biztrack.local');

    $data = test()->withHeaders($admin)
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'flagged',
            'reason' => 'Verified complaints from the public',
        ])
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKeys(['id', 'status', 'status_label'])
        ->and($data['status'])->toBe('flagged');

    /*
     * The fields the table shows are NOT in the response. A screen that swaps
     * the whole row for this payload loses them, which is exactly what happened.
     * If this endpoint ever starts returning the full record, delete this
     * expectation — do not let it fail quietly.
     */
    expect($data)->not->toHaveKey('name')
        ->and($data)->not->toHaveKey('owner');
});

it('records the status change as from, to and reason', function () {
    $business = aBusinessToRestatus();
    $before = $business->status;
    $admin = authAs('admin@biztrack.local');

    test()->withHeaders($admin)
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'blacklisted',
            'reason' => 'Falsified / misrepresented documents',
        ])
        ->assertOk();

    $log = App\Models\AuditLog::where('action', 'business.status_changed')
        ->where('auditable_id', $business->id)
        ->latest('id')
        ->firstOrFail();

    // The keys the timeline reads. `status` is deliberately absent: reading it
    // is what made a blacklisting render as "Active".
    expect($log->changes)->toHaveKeys(['from', 'to', 'reason'])
        ->and($log->changes['from'])->toBe($before)
        ->and($log->changes['to'])->toBe('blacklisted')
        ->and($log->changes['reason'])->toBe('Falsified / misrepresented documents')
        ->and($log->changes)->not->toHaveKey('status');
});

it('names the admin who changed the status', function () {
    $business = aBusinessToRestatus();
    $admin = authAs('admin@biztrack.local');

    test()->withHeaders($admin)
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'suspended',
            'reason' => 'Non-payment of assessed fees',
        ])
        ->assertOk();

    $log = App\Models\AuditLog::where('action', 'business.status_changed')
        ->where('auditable_id', $business->id)
        ->latest('id')
        ->firstOrFail();

    // A status history that cannot name who acted is not a history. The screen
    // shows this; an absent actor must stay distinguishable from a named one.
    expect($log->user_id)->not->toBeNull();
});
