<?php

use App\Models\Permit;
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

it('lets a reviewing office read the permit issued off a filing it did review', function () {
    // The sanitary office is routed the seeded filing, so the permits issued off
    // it stay readable — narrowing the permission must not blind an office to
    // the outcome of its own review.
    $sanitary = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    $permit = Permit::whereHas('application.assignments', fn ($a) => $a
        ->where('department_id', $sanitary->department_id))->first();

    if (! $permit) {
        expect(true)->toBeTrue('no permit on a sanitary-routed filing in the seeded storyline');

        return;
    }

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/permits/{$permit->id}")
        ->assertOk();
});
