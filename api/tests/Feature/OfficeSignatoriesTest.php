<?php

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\OfficeSignatory;
use App\Models\Role;
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

const SIGNATORIES = '/api/v1/admin/office-signatories';

function cenroId(): int
{
    return Department::where('code', 'CENRO')->value('id');
}

function chiefCenro(): OfficeSignatory
{
    return OfficeSignatory::where('department_id', cenroId())->where('role', 'Chief-CENRO')->firstOrFail();
}

/** The "Noted by" a CENRO report prints today. */
function cenroNotedBy(): ?array
{
    return test()->withHeaders(authAs('cenro@biztrack.local'))
        ->getJson('/api/v1/analytics/reports/clearances?from=2024-01-01&to=2026-12-31')
        ->assertOk()
        ->json('data.noted_by');
}

/* ── the table ────────────────────────────────────────────────────────── */

it('lets a retired holder’s post be filled by a successor', function () {
    $outgoing = chiefCenro();
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

/* ── who may edit them ────────────────────────────────────────────────── */

it('holds reference.manage on the super admin alone', function () {
    $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'reference.manage'))
        ->pluck('name')
        ->all();

    expect($holders)->toBe(['admin']);
});

it('refuses every account but the super admin, read and write alike', function (string $email) {
    $id = chiefCenro()->id;
    authAs($email);

    test()->getJson(SIGNATORIES)->assertForbidden();
    test()->postJson(SIGNATORIES, ['department_id' => cenroId(), 'role' => 'Clerk', 'name' => 'X'])->assertForbidden();
    test()->putJson(SIGNATORIES."/{$id}", ['name' => 'Someone Else'])->assertForbidden();
    test()->postJson(SIGNATORIES."/{$id}/retire")->assertForbidden();

    expect(chiefCenro()->name)->toBe('Mark Lloyd A. Mesina')
        ->and(chiefCenro()->is_active)->toBeTrue()
        ->and(OfficeSignatory::count())->toBe(2);
})->with([
    'BPLO' => 'bplo@biztrack.local',
    'the CENRO office itself' => 'cenro@biztrack.local',
    'Sanitary' => 'sanitary@biztrack.local',
    'Fire' => 'fire@biztrack.local',
    'Zoning' => 'zoning@biztrack.local',
    'OBO' => 'obo@biztrack.local',
    'a business owner' => 'owner@biztrack.local',
]);

it('asks a signed-out caller to sign in', function () {
    test()->getJson(SIGNATORIES)->assertUnauthorized();
});

/* ── the list ─────────────────────────────────────────────────────────── */

it('lists every office, the empty ones too, each in signature-block order', function () {
    // Inserted out of order, so a list that came back in insertion order fails.
    $bplo = Department::where('code', 'BPLO')->value('id');
    OfficeSignatory::create(['department_id' => $bplo, 'role' => 'Head', 'name' => 'C. Head', 'sort_order' => 5]);
    OfficeSignatory::create(['department_id' => $bplo, 'role' => 'Clerk', 'name' => 'A. Clerk', 'sort_order' => 1]);

    $offices = collect(test()->withHeaders(authAs('admin@biztrack.local'))->getJson(SIGNATORIES)->assertOk()->json('data'));

    expect($offices->pluck('code')->all())->toBe(['BFP', 'BPLO', 'CENRO', 'CHO', 'CPDO', 'OBO'])
        ->and($offices->firstWhere('code', 'CHO')['signatories'])->toBe([])
        ->and(collect($offices->firstWhere('code', 'BPLO')['signatories'])->pluck('name')->all())->toBe(['A. Clerk', 'C. Head'])
        ->and(collect($offices->firstWhere('code', 'CENRO')['signatories'])->pluck('role')->all())->toBe(['Evaluator', 'Chief-CENRO']);
});

/* ── adding and editing ───────────────────────────────────────────────── */

it('adds a signatory to an office that had none, and audits it', function () {
    $cho = Department::where('code', 'CHO')->value('id');

    $row = test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson(SIGNATORIES, ['department_id' => $cho, 'role' => 'City Health Officer', 'name' => 'Dr. A. Santos'])
        ->assertCreated()
        ->json('data');

    expect($row)->toMatchArray([
        'department_id' => $cho,
        'role' => 'City Health Officer',
        'name' => 'Dr. A. Santos',
        'sort_order' => 0,
        'is_active' => true,
    ])->and(AuditLog::where('action', 'office_signatory.created')->where('auditable_id', $row['id'])->exists())->toBeTrue();
});

it('puts a newcomer added without an order at the end of the block', function () {
    $row = test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson(SIGNATORIES, ['department_id' => cenroId(), 'role' => 'Records Officer', 'name' => 'R. Officer'])
        ->assertCreated()
        ->json('data');

    expect($row['sort_order'])->toBe(2);
});

it('edits the name, position, order and standing, and records what was replaced', function () {
    $chief = chiefCenro();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson(SIGNATORIES."/{$chief->id}", ['role' => 'CENRO Head', 'name' => 'New Name', 'sort_order' => 4, 'is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.role', 'CENRO Head')
        ->assertJsonPath('data.sort_order', 4);

    $log = AuditLog::where('action', 'office_signatory.updated')->where('auditable_id', $chief->id)->latest('id')->firstOrFail();
    expect($log->changes['from']['name'])->toBe('Mark Lloyd A. Mesina')
        ->and($log->changes['to']['name'])->toBe('New Name')
        ->and($log->snapshot)->toBeNull();
});

it('keeps a copy of the row when the edit dialog takes someone out of use', function () {
    $chief = chiefCenro();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson(SIGNATORIES."/{$chief->id}", ['is_active' => false])
        ->assertOk();

    $log = AuditLog::where('action', 'office_signatory.updated')->where('auditable_id', $chief->id)->latest('id')->firstOrFail();
    expect($log->snapshot['is_active'])->toBeTrue()
        ->and($log->snapshot['name'])->toBe('Mark Lloyd A. Mesina');
});

it('does not move a signatory to another office on edit', function () {
    $chief = chiefCenro();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson(SIGNATORIES."/{$chief->id}", ['department_id' => Department::where('code', 'BPLO')->value('id'), 'name' => 'Same Person'])
        ->assertOk();

    expect($chief->fresh()->department_id)->toBe(cenroId());
});

/* ── validation, in words a clerk can act on ──────────────────────────── */

it('says plainly what a new signatory is missing', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson(SIGNATORIES, [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.department_id.0', 'Choose the office this person signs for.')
        ->assertJsonPath('errors.role.0', 'Enter the position as it is printed under the name.')
        ->assertJsonPath('errors.name.0', 'Enter the name as it should be printed.');
});

it('refuses an order that is not a whole number from 0 to 99', function (mixed $order) {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson(SIGNATORIES.'/'.chiefCenro()->id, ['sort_order' => $order])
        ->assertUnprocessable()
        ->assertJsonPath('errors.sort_order.0', 'Enter the order as a whole number from 0 to 99.');
})->with([-1, 100, 'first', 1.5]);

it('refuses a blank name on edit rather than printing an empty line', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson(SIGNATORIES.'/'.chiefCenro()->id, ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Enter the name as it should be printed.');

    expect(chiefCenro()->name)->toBe('Mark Lloyd A. Mesina');
});

it('refuses a second current holder of a post, naming who holds it', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson(SIGNATORIES, ['department_id' => cenroId(), 'role' => 'chief-cenro', 'name' => 'Usurper', 'sort_order' => 5])
        ->assertUnprocessable()
        ->assertJsonPath('errors.role.0', 'CENRO already has a current Chief-CENRO: Mark Lloyd A. Mesina. Retire them first, or edit their entry instead.');
});

it('refuses two current signatories at one order, since only one can be Noted by', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson(SIGNATORIES, ['department_id' => cenroId(), 'role' => 'Records Officer', 'name' => 'R. Officer', 'sort_order' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.sort_order.0', 'Mark Lloyd A. Mesina is already at 1 in CENRO. Give each current signatory a different number.');
});

/* ── retiring ─────────────────────────────────────────────────────────── */

it('retires a signatory by keeping the row, with a copy of it on the audit trail', function () {
    $chief = chiefCenro();
    $before = OfficeSignatory::count();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson(SIGNATORIES."/{$chief->id}/retire")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $log = AuditLog::where('action', 'office_signatory.retired')->where('auditable_id', $chief->id)->sole();

    expect(OfficeSignatory::count())->toBe($before)
        ->and($chief->fresh()->is_active)->toBeFalse()
        ->and($chief->fresh()->name)->toBe('Mark Lloyd A. Mesina')
        ->and($log->snapshot['name'])->toBe('Mark Lloyd A. Mesina')
        ->and($log->snapshot['is_active'])->toBeTrue();
});

it('retires once, however often it is asked', function () {
    $chief = chiefCenro();
    authAs('admin@biztrack.local');

    test()->postJson(SIGNATORIES."/{$chief->id}/retire")->assertOk();
    test()->postJson(SIGNATORIES."/{$chief->id}/retire")->assertOk();

    expect(AuditLog::where('action', 'office_signatory.retired')->where('auditable_id', $chief->id)->count())->toBe(1);
});

it('has no route that deletes a signatory', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->deleteJson(SIGNATORIES.'/'.chiefCenro()->id)
        ->assertMethodNotAllowed();

    expect(OfficeSignatory::count())->toBe(2);
});

it('appoints a successor to a retired holder’s post, and will not bring the predecessor back over them', function () {
    $chief = chiefCenro();
    authAs('admin@biztrack.local');

    test()->postJson(SIGNATORIES."/{$chief->id}/retire")->assertOk();
    test()->postJson(SIGNATORIES, ['department_id' => cenroId(), 'role' => 'Chief-CENRO', 'name' => 'The Successor', 'sort_order' => 1])
        ->assertCreated();

    test()->putJson(SIGNATORIES."/{$chief->id}", ['is_active' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.role.0', 'CENRO already has a current Chief-CENRO: The Successor. Retire them first, or edit their entry instead.');
});

/* ── the order is what "Noted by" reads ───────────────────────────────── */

it('prints the highest-order current signatory as Noted by, and follows the edits', function () {
    expect(cenroNotedBy())->toBe(['name' => 'Mark Lloyd A. Mesina', 'position' => 'Chief-CENRO']);

    $evaluator = OfficeSignatory::where('department_id', cenroId())->where('role', 'Evaluator')->firstOrFail();

    // Moved below the chief: the evaluator now signs last.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson(SIGNATORIES."/{$evaluator->id}", ['sort_order' => 2])
        ->assertOk();
    expect(cenroNotedBy())->toBe(['name' => 'Elizabeth E. Gutierrez', 'position' => 'Evaluator']);

    // Retired: the chief is the last current name again.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson(SIGNATORIES."/{$evaluator->id}/retire")
        ->assertOk();
    expect(cenroNotedBy())->toBe(['name' => 'Mark Lloyd A. Mesina', 'position' => 'Chief-CENRO']);
});
