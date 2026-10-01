<?php

use App\Models\Department;
use App\Models\OfficeSignatory;
use Illuminate\Database\QueryException;

/*
 * Office Signatories — the names printed in each office's signature blocks.
 *
 * They are admin-edited data, never literals (docs/HANDOFF.md §9.2): the permit
 * certificate prints an office's current signatories in order, and the report
 * screen prints the last of them as "Noted by". Until this file the controller
 * that edits them was wired to no route, so every office but CENRO printed a
 * blank line and nobody could fill it.
 */

function cenroId(): int
{
    return Department::where('code', 'CENRO')->value('id');
}

/* ── the table ────────────────────────────────────────────────────────── */

it('lets a retired holder’s post be filled by a successor', function () {
    $outgoing = OfficeSignatory::where('department_id', cenroId())->where('role', 'Chief-CENRO')->firstOrFail();
    $outgoing->update(['is_active' => false]);

    $successor = OfficeSignatory::create([
        'department_id' => cenroId(),
        'role' => 'Chief-CENRO',
        'name' => 'New Chief',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    expect($successor->exists)->toBeTrue()
        ->and($outgoing->fresh()->name)->toBe('Mark Lloyd A. Mesina');
});

it('still refuses two current holders of one post, at the database', function () {
    OfficeSignatory::create([
        'department_id' => cenroId(),
        'role' => 'Chief-CENRO',
        'name' => 'A Second Chief',
        'sort_order' => 2,
        'is_active' => true,
    ]);
})->throws(QueryException::class);
