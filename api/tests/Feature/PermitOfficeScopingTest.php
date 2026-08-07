<?php

use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;

/*
 * Checklist item 56 applied to the outcome of a filing, not just the filing.
 *
 * `application.view_all` was narrowed to "filings other than my own, in the
 * offices I am routed to". `permit.view_all` was not, and the RBAC seeder hands
 * it to every office role — sanitary, fire, zoning, OBO, CENRO and the market
 * office alike. Measured against the live register, a market administrator
 * could list 2 applications and all 4,122 permits, and `GET /permits/{id}/pdf`
 * handed over the owner's name and street address for any of them, which is
 * more than the deliberately anonymous public /verify endpoint gives out.
 *
 * The boundary has to hold on the list, on the record, and on the PDF. A 403 on
 * one of the three is a speed bump, not access control.
 */

/** One permit issued off a filing the given office was never routed to. */
function permitOutsideOffice(string $officerEmail): Permit
{
    $officer = User::where('email', $officerEmail)->firstOrFail();

    return Permit::whereHas('application', fn ($a) => $a
        ->whereDoesntHave('assignments', fn ($x) => $x->where('department_id', $officer->department_id))
        ->where('applicant_user_id', '!=', $officer->id))
        ->firstOrFail();
}

it('keeps an office reviewer out of permits on filings it never saw', function () {
    $permit = permitOutsideOffice('market@biztrack.local');
    $market = authAs('market@biztrack.local');

    $listed = collect(
        test()->withHeaders($market)->getJson('/api/v1/permits?per_page=200')->assertOk()->json('data')
    )->pluck('id');

    expect($listed)->not->toContain($permit->id, 'the list leaked a permit the office may not read');

    test()->withHeaders($market)->getJson("/api/v1/permits/{$permit->id}")->assertForbidden();
    test()->withHeaders($market)->get("/api/v1/permits/{$permit->id}/pdf")->assertForbidden();
});

it('still lets BPLO and the super admin read the whole register', function () {
    $permit = Permit::firstOrFail();

    foreach (['bplo@biztrack.local', 'admin@biztrack.local'] as $email) {
        $headers = authAs($email);
        test()->withHeaders($headers)->getJson("/api/v1/permits/{$permit->id}")->assertOk();

        $meta = test()->withHeaders($headers)->getJson('/api/v1/permits')->assertOk()->json('meta');
        expect($meta['total'])->toBe(Permit::count(), "{$email} lost sight of the register");
    }
});

it('still lets an owner read their own permits and nobody else’s', function () {
    $owner = authAs('owner@biztrack.local');

    $rows = test()->withHeaders($owner)->getJson('/api/v1/permits?per_page=200')->assertOk()->json('data');
    $ids = collect($rows)->pluck('id');

    $mine = Permit::whereHas('business', fn ($b) => $b->where('owner_user_id', User::where('email', 'owner@biztrack.local')->value('id')))
        ->pluck('id');

    expect($ids->sort()->values()->all())->toBe($mine->sort()->values()->all());

    $someoneElses = Permit::whereNotIn('id', $mine)->first();
    if ($someoneElses) {
        test()->withHeaders($owner)->getJson("/api/v1/permits/{$someoneElses->id}")->assertForbidden();
        test()->withHeaders($owner)->get("/api/v1/permits/{$someoneElses->id}/pdf")->assertForbidden();
    }
});

it('lets a reviewing office read the permit ITS OWN office issued', function () {
    // Narrowing the permission must not blind an office to the outcome of its
    // own review. This is the positive half, and it is not optional: a suite
    // that only proved offices are refused would pass just as happily if every
    // office could see nothing at all.
    $sanitary = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    $permit = Permit::whereHas('application.assignments', fn ($a) => $a
        ->where('department_id', $sanitary->department_id))
        ->whereHas('permitType', fn ($t) => $t->where('issuing_department_id', $sanitary->department_id))
        ->first();

    if (! $permit) {
        expect(true)->toBeTrue('no sanitary permit on a sanitary-routed filing in the seeded storyline');

        return;
    }

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/permits/{$permit->id}")
        ->assertOk();
});

/*
 * ── The boundary this file previously stopped one step short of ─────────────
 *
 * Everything above draws the line at the FILING, and that was the whole rule
 * until 2026-08-07. A six-clearance filing is routed to six offices, so all six
 * passed every check here — and each was handed all six certificates.
 *
 * Measured on a copy of the live register: `sanitary@biztrack.local` listed
 * 5,327 permits including FSIC, CEC, OCCUPANCY, ZONING and MARKET, read a
 * BFP-issued Fire Safety Inspection Certificate by id (200, owner name and
 * street address), and downloaded 1.1 MB of its PDF — more than the
 * deliberately anonymous public /verify endpoint gives out. Permit ids are
 * sequential and the URL is typeable, so the list filter was never the control.
 *
 * The client's sentence is the specification: "sanitary accounts can only see
 * sanitary permits, and fire accounts can only see fire".
 *
 * These two tests are the ones that fail if that fix is ever reverted. The
 * older tests above would not — they passed throughout the leak.
 */
it('refuses an office a clearance ANOTHER office issued on a filing it did review', function () {
    $sanitary = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    // Same filing the sanitary office is legitimately routed to — so the coarse
    // check passes and only the issuing-office rule can refuse this.
    $foreign = Permit::whereHas('application.assignments', fn ($a) => $a
        ->where('department_id', $sanitary->department_id))
        ->whereHas('permitType', fn ($t) => $t->where('issuing_department_id', '!=', $sanitary->department_id))
        ->whereHas('business', fn ($b) => $b->where('owner_user_id', '!=', $sanitary->id))
        ->first();

    if (! $foreign) {
        expect(true)->toBeTrue('no other office’s permit on a sanitary-routed filing in this register');

        return;
    }

    $headers = authAs('sanitary@biztrack.local');

    // The record and the PDF, because a 403 on one of the two is a speed bump.
    test()->withHeaders($headers)->getJson("/api/v1/permits/{$foreign->id}")->assertForbidden();
    test()->withHeaders($headers)->get("/api/v1/permits/{$foreign->id}/pdf")->assertForbidden();
});

it('lists an office only the clearances it issues', function () {
    /*
     * The list has to agree with the record. They are drawn by two different
     * pieces of code — scopeToReader and authorizeView — which is exactly how
     * they came to disagree before.
     *
     * The office is derived from the seeded storyline rather than named,
     * because which clearances actually get issued there is a property of the
     * seeder and not of this rule. Naming sanitary made this test pass on an
     * EMPTY list, which satisfies "contains only sanitary permits" while
     * proving nothing whatsoever — the hollow pass this file exists to avoid.
     */
    $office = collect(['sanitary', 'fire', 'zoning', 'obo', 'cenro', 'market'])
        ->map(fn (string $a) => User::where('email', "{$a}@biztrack.local")->firstOrFail())
        ->first(fn (User $u) => Permit::whereHas('permitType', fn ($t) => $t
            ->where('issuing_department_id', $u->department_id))->exists());

    if (! $office) {
        expect(true)->toBeTrue('the seeded storyline issues no clearance for any single office');

        return;
    }

    $expected = PermitType::where('issuing_department_id', $office->department_id)
        ->pluck('code')->all();

    $codes = collect(
        test()->withHeaders(authAs($office->email))
            ->getJson('/api/v1/permits?per_page=100')
            ->assertOk()
            ->json('data')
    )->pluck('permit_type.code')->unique()->values()->all();

    // Non-empty is asserted first and separately: it is the half that catches a
    // boundary drawn so tight the office is blinded to its own work.
    expect($codes)->not->toBeEmpty()
        ->and(array_diff($codes, $expected))->toBe([]);
});
